<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\RefreshToken;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AuthService;
use App\Services\RefreshTokenService;
use App\Services\SessionService;
use App\Services\TokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;

class RefreshTokenTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('test-only-signing-key-', 4)]);
    }

    public function test_rotation_consumes_the_previous_token_without_extending_the_session(): void
    {
        $user = User::factory()->create();
        $first = app(AuthService::class)->login($user->email, 'password');
        $this->travel(5)->minutes();
        $second = app(RefreshTokenService::class)->rotate($first->refreshToken);

        $this->assertSame($first->session->id, $second->session->id);
        $this->assertTrue($first->refreshToken !== $second->refreshToken);
        $this->assertTrue($first->accessToken !== $second->accessToken);
        $this->assertTrue($first->session->expires_at->equalTo($second->session->expires_at));
        $rows = RefreshToken::query()->where('auth_session_id', $first->session->id)->get();
        $this->assertCount(2, $rows);
        $this->assertCount(1, $rows->whereNull('consumed_at'));
        $this->assertNotNull($rows->firstWhere('token_hash', hash('sha256', $first->refreshToken))->consumed_at);
    }

    public function test_reuse_commits_family_revocation_before_returning_failure(): void
    {
        $user = User::factory()->create();
        $first = app(AuthService::class)->login($user->email, 'password');
        $second = app(RefreshTokenService::class)->rotate($first->refreshToken);

        $this->assertRejected(fn () => app(RefreshTokenService::class)->rotate($first->refreshToken));
        $this->assertSame('refresh_reuse', $first->session->refresh()->revocation_reason);
        $this->assertNotNull($first->session->revoked_at);
        $this->assertRejected(fn () => app(RefreshTokenService::class)->rotate($second->refreshToken));
        $this->assertRejected(fn () => app(SessionService::class)->authenticate($second->session->id, (int) $user->id));
        $this->assertSame($second->session->id, app(TokenService::class)->decode($second->accessToken)['sid']);
        $this->assertSame(1, SecurityEvent::query()->where('event', 'auth.refresh.reused')->where('actor_user_id', $user->id)->count());
    }

    public function test_expired_session_cannot_rotate_at_its_exact_expiration(): void
    {
        $user = User::factory()->create();
        $first = app(AuthService::class)->login($user->email, 'password');
        $this->travelTo($first->session->expires_at);

        $this->assertRejected(fn () => app(RefreshTokenService::class)->rotate($first->refreshToken));
        $this->assertSame(1, RefreshToken::query()->where('auth_session_id', $first->session->id)->count());
    }

    public function test_disabled_user_cannot_rotate_a_valid_unconsumed_token(): void
    {
        $user = User::factory()->create();
        $first = app(AuthService::class)->login($user->email, 'password');
        $user->forceFill(['active' => false])->save();

        $this->assertRejected(fn () => app(RefreshTokenService::class)->rotate($first->refreshToken));
        $this->assertSame(1, RefreshToken::query()->where('auth_session_id', $first->session->id)->count());
    }

    public function test_logout_with_expired_access_uses_refresh_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $first = app(AuthService::class)->login($user->email, 'password');
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();
        $this->assertRejected(fn () => app(TokenService::class)->decode($first->accessToken));

        app(RefreshTokenService::class)->revokeByToken($first->refreshToken);
        app(RefreshTokenService::class)->revokeByToken($first->refreshToken);
        app(RefreshTokenService::class)->revokeByToken('desconocido');
        $this->assertRejected(fn () => app(RefreshTokenService::class)->rotate($first->refreshToken));
        $this->assertSame('logout', $first->session->refresh()->revocation_reason);
        $this->assertSame(1, SecurityEvent::query()->where('event', 'auth.session.revoked')->where('actor_user_id', $user->id)->count());
    }

    public function test_invalid_and_unknown_refresh_tokens_fail_without_creating_sessions(): void
    {
        $before = AuthSession::query()->count();
        foreach (['', str_repeat('x', 4096), str_repeat('a', 64)] as $token) {
            $this->assertRejected(fn () => app(RefreshTokenService::class)->rotate($token));
        }

        $this->assertSame($before, AuthSession::query()->count());
    }

    public function test_database_rejects_two_unconsumed_refresh_tokens_for_one_family(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');

        try {
            DB::transaction(fn () => RefreshToken::query()->create([
                'auth_session_id' => $pair->session->id,
                'token_hash' => hash('sha256', random_bytes(32)),
                'expires_at' => $pair->session->expires_at,
            ]));
            $this->fail('La base permitió dos refresh vigentes en la misma familia.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
    }

    public function test_two_concurrent_refreshes_revoke_the_family_after_one_rotation(): void
    {
        // Los workers necesitan un fixture confirmado; esta conexión nunca usa la base operativa.
        $original = DB::getDefaultConnection();
        $database = config('database.connections.pgsql.database');
        $this->assertSame(env('DB_TEST_DATABASE', 'sysforest_testing'), $database);
        config(['database.connections.auth_concurrency' => config('database.connections.pgsql')]);
        DB::purge('auth_concurrency');
        DB::setDefaultConnection('auth_concurrency');
        $connection = DB::connection('auth_concurrency');
        $user = null;
        $workers = [];

        try {
            $user = User::factory()->create();
            $pair = app(AuthService::class)->login($user->email, 'password');
            $connection->beginTransaction();
            $connection->table('users')->where('id', $user->id)->lockForUpdate()->first();
            $input = json_encode([
                'token' => $pair->refreshToken, 'secret' => config('jwt.secret'),
                'issuer' => config('security.issuer'), 'audience' => config('security.audience'),
            ], JSON_THROW_ON_ERROR);

            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-r', $this->workerScript()], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database,
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
                ]);
                // El refresh y la clave viajan por stdin, nunca en argumentos ni salida del proceso.
                $worker->setInput($input."\n");
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }

            $pids = [];
            $readyDeadline = microtime(true) + 5;
            do {
                foreach ($workers as $index => $worker) {
                    if (preg_match('/READY:([0-9]+)/', $worker->getOutput(), $match)) {
                        $pids[$index] = (int) $match[1];
                    }
                }
                if (count($pids) === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $readyDeadline);
            $this->assertCount(2, $pids, 'Los workers no llegaron al punto de coordinación.');

            $deadline = microtime(true) + 5;
            do {
                $connection->select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) $connection->selectOne(
                    "SELECT count(*) AS total FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = 'Lock'",
                    $pids,
                )->total;
                if ($waiting === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            $this->assertSame(2, $waiting, 'Ambas transacciones deben competir por el mismo usuario antes de liberarlo.');
            $connection->commit();

            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), 'El worker de refresh falló.');
                preg_match('/RESULT:(ok|denied)/', $worker->getOutput(), $match);
                $outcomes[] = $match[1] ?? 'error';
            }
            sort($outcomes);
            $this->assertSame(['denied', 'ok'], $outcomes);
            $this->assertSame('refresh_reuse', $pair->session->refresh()->revocation_reason);
            $this->assertSame(2, RefreshToken::query()->where('auth_session_id', $pair->session->id)->count());
            $this->assertRejected(fn () => app(SessionService::class)->authenticate($pair->session->id, (int) $user->id));
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0.5);
                }
            }
            if ($user !== null) {
                $connection->table('security_events')->where('actor_user_id', $user->id)->delete();
                $connection->table('auth_sessions')->where('user_id', $user->id)->delete();
                $connection->table('users')->where('id', $user->id)->delete();
            }
            DB::setDefaultConnection($original);
            DB::purge('auth_concurrency');
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La operación debía rechazar la credencial.');
        } catch (AuthenticationException) {
            $this->assertTrue(true);
        }
    }

    private function workerScript(): string
    {
        return <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== env('DB_TEST_DATABASE', 'sysforest_testing')) {
    fwrite(STDERR, 'Base de pruebas rechazada.');
    exit(2);
}
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['jwt.secret' => $input['secret'], 'security.issuer' => $input['issuer'], 'security.audience' => $input['audience']]);
$pid = \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
echo 'READY:'.$pid.PHP_EOL;
fflush(STDOUT);
try {
    app(\App\Services\RefreshTokenService::class)->rotate($input['token']);
    echo 'RESULT:ok'.PHP_EOL;
} catch (\Illuminate\Auth\AuthenticationException) {
    echo 'RESULT:denied'.PHP_EOL;
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception));
    exit(3);
}
PHP;
    }
}
