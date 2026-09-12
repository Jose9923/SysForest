<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\TreeCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;

class TreeCodeConcurrencyTest extends PostgresTestCase
{
    /** Dos conexiones compiten de verdad por el mismo consecutivo antes de confirmar sus reservas. */
    public function test_concurrent_organization_sequences_do_not_duplicate_codes(): void
    {
        $database = config('database.connections.pgsql.database');
        $this->assertSame(env('DB_TEST_DATABASE', 'sysforest_testing'), $database);
        config(['database.connections.tree_concurrency' => config('database.connections.pgsql')]);
        DB::purge('tree_concurrency');
        $connection = DB::connection('tree_concurrency');
        $ids = array_map(fn () => (string) Str::uuid(), range(1, 5));
        [$country, $department, $municipality, $organization, $secondOrganization] = $ids;
        $suffix = strtoupper(Str::random(8));
        $workers = [];
        try {
            $connection->transaction(function () use ($connection, $country, $department, $municipality, $organization, $secondOrganization, $suffix): void {
                $connection->table('countries')->insert(['id' => $country, 'code' => 'C'.$suffix, 'name' => 'Fixture concurrente']);
                $connection->table('departments')->insert(['id' => $department, 'country_id' => $country, 'code' => 'D'.$suffix, 'name' => 'Fixture']);
                $connection->table('municipalities')->insert(['id' => $municipality, 'department_id' => $department, 'code' => 'M'.$suffix, 'name' => 'Fixture']);
                foreach ([$organization, $secondOrganization] as $index => $id) {
                    $connection->table('organizations')->insert(['id' => $id, 'municipality_id' => $municipality, 'type' => 'other', 'name' => 'Fixture de concurrencia', 'code' => 'SEQ-'.$suffix.'-'.$index, 'tree_code_prefix' => 'SEQ-'.$suffix.'-'.$index]);
                    $connection->table('organization_sequences')->insert(['organization_id' => $id, 'name' => 'tree', 'value' => 0]);
                }
            });
            $connection->beginTransaction();
            $connection->table('organization_sequences')->where('organization_id', $organization)->lockForUpdate()->first();
            for ($index = 0; $index < 2; $index++) {
                $worker = new Process([PHP_BINARY, '-r', $this->script()], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database,
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
                ]);
                $worker->setInput(json_encode(['organization' => $organization], JSON_THROW_ON_ERROR)."\n");
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $pids = [];
            $deadline = microtime(true) + 8;
            do {
                foreach ($workers as $index => $worker) {
                    if (preg_match('/READY:(\d+)/', $worker->getOutput(), $match)) {
                        $pids[$index] = (int) $match[1];
                    }
                }
                if (count($pids) === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $pids);
            $waiting = 0;
            do {
                $connection->select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = 'Lock'", $pids)->total;
                if ($waiting === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Ambas reservas deben competir por la misma secuencia PostgreSQL.');
            $connection->commit();
            $codes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                preg_match('/RESULT:([A-Z0-9-]+)/', $worker->getOutput(), $match);
                $codes[] = $match[1] ?? '';
            }
            sort($codes);
            $this->assertSame(['SEQ-'.$suffix.'-0-000001', 'SEQ-'.$suffix.'-0-000002'], $codes);
            $this->assertSame(2, (int) $connection->table('organization_sequences')->where('organization_id', $organization)->value('value'));

            // El mismo servicio empieza en uno para otra organización; no existe una secuencia global.
            $original = DB::getDefaultConnection();
            try {
                DB::setDefaultConnection('tree_concurrency');
                $second = Organization::query()->findOrFail($secondOrganization);
                $code = $connection->transaction(fn () => app(TreeCodeService::class)->next($second));
                $this->assertSame('SEQ-'.$suffix.'-1-000001', $code);
            } finally {
                DB::setDefaultConnection($original);
            }
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0.5);
                }
            }
            // Solo se eliminan las cinco identidades generadas por este fixture en sysforest_testing.
            $connection->table('organization_sequences')->whereIn('organization_id', [$organization, $secondOrganization])->delete();
            $connection->table('organizations')->whereIn('id', [$organization, $secondOrganization])->delete();
            $connection->table('municipalities')->where('id', $municipality)->delete();
            $connection->table('departments')->where('id', $department)->delete();
            $connection->table('countries')->where('id', $country)->delete();
            DB::purge('tree_concurrency');
        }
    }

    private function script(): string
    {
        return <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.connections.pgsql.database') !== env('DB_TEST_DATABASE', 'sysforest_testing')) {
    exit(2);
}
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$organization = \App\Models\Organization::query()->findOrFail($input['organization']);
echo 'READY:'.\Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid.PHP_EOL;
fflush(STDOUT);
$code = \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\TreeCodeService::class)->next($organization));
echo 'RESULT:'.$code.PHP_EOL;
PHP;
    }
}
