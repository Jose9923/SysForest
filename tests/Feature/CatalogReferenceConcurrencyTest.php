<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\TreeCatalogRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;

class CatalogReferenceConcurrencyTest extends PostgresTestCase
{
    /** La segunda conexión debe esperar al commit antes de desactivar una referencia ya validada. */
    #[DataProvider('references')]
    public function test_capture_keeps_reference_available_until_its_transaction_finishes(string $field, string $target): void
    {
        $database = config('database.connections.pgsql.database');
        $this->assertSame(env('DB_TEST_DATABASE', 'sysforest_testing'), $database);
        config(['database.connections.catalog_concurrency' => config('database.connections.pgsql')]);
        DB::purge('catalog_concurrency');
        $connection = DB::connection('catalog_concurrency');
        $ids = ['placements' => (string) Str::uuid(), 'families' => (string) Str::uuid(),
            'species' => (string) Str::uuid(), 'deletion_reasons' => (string) Str::uuid()];
        $code = 'CONCURRENT_'.strtoupper(Str::random(12));
        $worker = null;
        $originalConnection = DB::getDefaultConnection();
        try {
            // Solo estos UUID se confirman en sysforest_testing para hacerlos visibles al otro proceso.
            $connection->transaction(function () use ($connection, $ids, $code): void {
                foreach (['placements', 'deletion_reasons'] as $table) {
                    $connection->table($table)->insert(['id' => $ids[$table], 'code' => $code, 'name' => $code]);
                }
                $connection->table('families')->insert(['id' => $ids['families'], 'code' => $code, 'scientific_name' => $code]);
                $connection->table('species')->insert(['id' => $ids['species'], 'code' => $code,
                    'scientific_name' => $code, 'family_id' => $ids['families']]);
            });
            $reference = match ($field) {
                'placement_id' => $ids['placements'],
                'species_id' => $ids['species'],
                default => $ids['deletion_reasons'],
            };
            $connection->beginTransaction();
            DB::setDefaultConnection('catalog_concurrency');
            $this->assertTrue(app(TreeCatalogRepository::class)->validSelection($field, $reference));
            DB::setDefaultConnection($originalConnection);

            $worker = new Process([PHP_BINARY, '-r', $this->script()], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
            ]);
            $worker->setInput(json_encode(['table' => $target, 'id' => $ids[$target]], JSON_THROW_ON_ERROR)."\n");
            $worker->setTimeout(20);
            $worker->start();
            $pid = null;
            $deadline = microtime(true) + 8;
            do {
                if (preg_match('/READY:(\d+)/', $worker->getOutput(), $match)) {
                    $pid = (int) $match[1];
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertNotNull($pid, $worker->getErrorOutput());
            $waiting = false;
            do {
                $connection->select('SELECT pg_stat_clear_snapshot()');
                $waiting = (bool) $connection->selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock') AS waiting", [$pid])->waiting;
                if ($waiting || !$worker->isRunning()) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'La desactivación debe esperar al bloqueo compartido de la captura.');
            $this->assertTrue((bool) $connection->table($target)->where('id', $ids[$target])->value('active'));

            $connection->commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertStringContainsString('UPDATED:1', $worker->getOutput());
            DB::setDefaultConnection('catalog_concurrency');
            $this->assertFalse(app(TreeCatalogRepository::class)->validSelection($field, $reference));
            $this->assertTrue(app(TreeCatalogRepository::class)->validSelection($field, $reference, $reference), 'La referencia histórica se conserva.');
        } finally {
            DB::setDefaultConnection($originalConnection);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($worker?->isRunning()) {
                $worker->stop(0.5);
            }
            foreach (['species', 'families', 'placements', 'deletion_reasons'] as $table) {
                $connection->table($table)->where('id', $ids[$table])->delete();
            }
            DB::purge('catalog_concurrency');
        }
    }

    public static function references(): array
    {
        return [['placement_id', 'placements'], ['species_id', 'species'],
            ['species_id', 'families'], ['deletion_reason_id', 'deletion_reasons']];
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
if (!in_array($input['table'], ['placements', 'families', 'species', 'deletion_reasons'], true)) {
    exit(3);
}
echo 'READY:'.\Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid.PHP_EOL;
fflush(STDOUT);
$updated = \Illuminate\Support\Facades\DB::table($input['table'])->where('id', $input['id'])->update(['active' => false]);
echo 'UPDATED:'.$updated.PHP_EOL;
PHP;
    }
}
