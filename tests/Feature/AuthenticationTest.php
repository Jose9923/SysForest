<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AuthCookies;
use App\Http\Middleware\AuthenticateJwtCookie;
use App\Models\AuthSession;
use App\Models\RefreshToken;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SessionService;
use App\Services\TokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\PostgresTestCase;

class AuthenticationTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('test-only-signing-key-', 4)]);
        Route::middleware(['web', AuthenticateJwtCookie::class])->match(['GET', 'POST'], '/__tests/jwt', function (Request $request) {
            return response()->json([
                'user_id' => $request->user()->id,
                'session_id' => $request->attributes->get('auth_session')->id,
            ]);
        });
    }

    public function test_login_creates_a_signed_access_and_only_a_refresh_hash_in_database(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login(strtoupper($user->email), 'password');
        $claims = app(TokenService::class)->decode($pair->accessToken);

        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame($pair->session->id, $claims['sid']);
        $this->assertSame((string) config('security.issuer'), $claims['iss']);
        $this->assertSame([(string) config('security.audience')], $claims['aud']);
        $this->assertArrayNotHasKey('roles', $claims);
        $this->assertArrayNotHasKey('organization_id', $claims);
        $this->assertSame(64, strlen($pair->refreshToken));
        $stored = RefreshToken::query()->where('auth_session_id', $pair->session->id)->sole();
        $this->assertTrue(hash_equals($stored->token_hash, hash('sha256', $pair->refreshToken)));
        $this->assertStringNotContainsString($pair->refreshToken, json_encode($pair, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($pair->accessToken, json_encode($pair, JSON_THROW_ON_ERROR));
        $event = SecurityEvent::query()->where('event', 'auth.login.succeeded')->where('actor_user_id', $user->id)->sole();
        $this->assertSame(['session_id' => $pair->session->id], $event->metadata);
    }

    public function test_unverified_account_can_login_but_remains_unverified(): void
    {
        $user = User::factory()->unverified()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');

        $this->assertFalse($pair->user->hasVerifiedEmail());
        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->withCredentials()->getJson('/__tests/jwt')->assertOk()->assertJsonPath('user_id', $user->id);
    }

    public function test_wrong_password_unknown_account_and_disabled_user_have_the_same_failure(): void
    {
        $user = User::factory()->create();
        $disabled = User::factory()->create(['active' => false]);
        $messages = [];

        foreach ([[$user->email, 'incorrecta'], ['inexistente@sysforest.test', 'password'], [$disabled->email, 'password']] as [$email, $password]) {
            try {
                app(AuthService::class)->login($email, $password);
                $this->fail('La autenticación debía rechazarse.');
            } catch (AuthenticationException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertCount(1, array_unique($messages));
        $this->assertSame(0, AuthSession::query()->whereIn('user_id', [$user->id, $disabled->id])->count());
        $events = SecurityEvent::query()->where('event', 'auth.login.failed')->get();
        $this->assertCount(3, $events);
        $this->assertTrue($events->every(fn (SecurityEvent $event) => $event->actor_user_id === null && $event->metadata === []));
    }

    public function test_only_the_access_cookie_authenticates_http_requests(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');
        $this->withToken($pair->accessToken)->getJson('/__tests/jwt')->assertUnauthorized();
        $this->withoutToken()->getJson('/__tests/jwt?token='.urlencode($pair->accessToken))->assertUnauthorized();
        $this->postJson('/__tests/jwt', ['token' => $pair->accessToken])->assertUnauthorized();
        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->withCredentials()->getJson('/__tests/jwt')->assertOk();
    }

    public function test_valid_signature_does_not_allow_a_revoked_session(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');
        app(SessionService::class)->revoke($pair->session, 'logout');

        $this->assertSame($pair->session->id, app(TokenService::class)->decode($pair->accessToken)['sid']);
        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->withCredentials()->getJson('/__tests/jwt')->assertUnauthorized();
    }

    public function test_disabled_user_is_rechecked_even_without_explicit_session_revocation(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');
        $user->forceFill(['active' => false])->save();

        $this->assertNull($pair->session->refresh()->revoked_at);
        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->withCredentials()->getJson('/__tests/jwt')->assertUnauthorized();
    }

    public function test_expired_access_does_not_authenticate(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();

        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->withCredentials()->getJson('/__tests/jwt')->assertUnauthorized();
    }

    public function test_issuer_and_audience_are_checked_against_server_configuration(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');

        foreach (['issuer', 'audience'] as $key) {
            $previous = config('security.'.$key);
            config(['security.'.$key => 'otro-receptor']);
            try {
                app(TokenService::class)->decode($pair->accessToken);
                $this->fail('Un emisor o audiencia diferente debía rechazarse.');
            } catch (AuthenticationException) {
                $this->assertTrue(true);
            } finally {
                config(['security.'.$key => $previous]);
            }
        }
    }

    public function test_tampered_signature_and_mismatched_session_subject_are_rejected(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');
        $parts = explode('.', $pair->accessToken);
        $parts[2] = ($parts[2][0] === 'A' ? 'B' : 'A').substr($parts[2], 1);
        $this->withCookie(config('security.access_cookie'), implode('.', $parts))
            ->withCredentials()->getJson('/__tests/jwt')->assertUnauthorized();

        $this->expectException(AuthenticationException::class);
        app(SessionService::class)->authenticate($pair->session->id, (int) $other->id);
    }

    public function test_revoking_all_sessions_is_scoped_to_the_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = app(AuthService::class)->login($user->email, 'password');
        $second = app(AuthService::class)->login($user->email, 'password');
        $unrelated = app(AuthService::class)->login($other->email, 'password');
        app(SessionService::class)->revokeAll($user, 'password_changed');

        $this->assertNotNull($first->session->refresh()->revoked_at);
        $this->assertNotNull($second->session->refresh()->revoked_at);
        $this->assertNull($unrelated->session->refresh()->revoked_at);
        $this->assertSame($other->id, app(SessionService::class)->authenticate($unrelated->session->id, (int) $other->id)->user->id);
    }

    public function test_middleware_clears_actor_after_success_and_exception(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');
        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->withCredentials()->getJson('/__tests/jwt')->assertOk();
        $this->assertFalse(Auth::guard('web')->hasUser());
        $this->withCookie(config('security.access_cookie'), '')->getJson('/__tests/jwt')->assertUnauthorized();

        $request = Request::create('/__tests/direct', 'GET', [], [config('security.access_cookie') => $pair->accessToken]);
        try {
            app(AuthenticateJwtCookie::class)->handle($request, function (Request $current) use ($user): never {
                $this->assertSame($user->id, $current->user()->id);
                throw new RuntimeException('Fallo simulado después de autenticar.');
            });
        } catch (RuntimeException) {
            $this->assertFalse(Auth::guard('web')->hasUser());
            $this->assertFalse($request->attributes->has('auth_session'));
        }
    }

    public function test_cookies_are_httponly_host_only_and_secure_except_local_http(): void
    {
        $user = User::factory()->create();
        $pair = app(AuthService::class)->login($user->email, 'password');
        $environment = app()->environment();
        config(['app.url' => 'http://localhost:8080']);

        try {
            app()->instance('env', 'production');
            $response = app(AuthCookies::class)->attach(response(''), $pair);
            $this->assertCount(2, $response->headers->getCookies());
            foreach ($response->headers->getCookies() as $cookie) {
                $this->assertTrue($cookie->isHttpOnly());
                $this->assertTrue($cookie->isSecure());
                $this->assertNull($cookie->getDomain());
                $this->assertSame('/', $cookie->getPath());
                $this->assertSame('lax', $cookie->getSameSite());
            }

            app()->instance('env', 'local');
            foreach (app(AuthCookies::class)->attach(response(''), $pair)->headers->getCookies() as $cookie) {
                $this->assertFalse($cookie->isSecure());
            }
            foreach (app(AuthCookies::class)->clear(response(''))->headers->getCookies() as $cookie) {
                $this->assertSame('/', $cookie->getPath());
                $this->assertLessThan(time(), $cookie->getExpiresTime());
            }
        } finally {
            app()->instance('env', $environment);
        }
    }
}
