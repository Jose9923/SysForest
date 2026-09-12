<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\PostgresTestCase;

class IdentityHttpTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withCredentials();

        // Solo se quita la excepción de PHPUnit: se ejecuta la protección CSRF real.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_login_form_contains_csrf_and_security_headers(): void
    {
        $response = $this->get('/login')->assertOk();
        $response->assertSee('name="_token"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertNull($response->getCookie(config('security.access_cookie')));
        $this->assertNull($response->getCookie(config('security.refresh_cookie')));
    }

    public function test_login_changes_browser_session_and_does_not_inherit_a_forged_tenant(): void
    {
        $own = $this->pilot();
        $foreign = $this->anotherOrganization($own);
        $user = User::factory()->create();
        $this->member($user, $own);
        $this->withSession(['active_organization_id' => $foreign->id, 'untrusted_session_marker' => 'anterior']);
        $csrf = $this->csrf();
        $oldId = session()->getId();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', '_token' => $csrf])
            ->assertRedirect(route('organizations.index'));
        $this->rememberCookies($response);

        $this->assertNotSame($oldId, session()->getId());
        $this->assertTrue($csrf !== session()->token());
        $response->assertSessionMissing('untrusted_session_marker')->assertSessionMissing('active_organization_id');
        $session = AuthSession::query()->findOrFail(session('auth_session_id'));
        $this->assertSame((int) $user->id, $session->user_id);
        $this->get('/organizations')->assertOk()->assertSee($own->name)->assertSessionMissing('active_organization_id');
        $this->post('/active-organization', [
            'organization_id' => $own->id, '_token' => $this->csrf('/organizations'),
        ])->assertRedirect(route('organizations.show', $own->id))->assertSessionHas('active_organization_id', $own->id);
        $this->get('/profile')->assertOk()->assertSee($user->email);
    }

    public function test_login_delivers_tokens_only_as_protected_cookies(): void
    {
        $user = User::factory()->create();
        $response = $this->signIn($user);

        foreach (['access_cookie', 'refresh_cookie'] as $key) {
            $cookie = $response->getCookie(config('security.'.$key));
            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertTrue($cookie->isSecure());
            $this->assertNull($cookie->getDomain());
            $this->assertSame('/', $cookie->getPath());
            $this->assertSame('lax', $cookie->getSameSite());
            $this->assertStringNotContainsString($cookie->getValue(), (string) $response->getContent());
            $this->assertStringNotContainsString($cookie->getValue(), $response->headers->get('Location'));
        }
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_login_rejects_missing_or_incorrect_csrf_without_creating_a_session(): void
    {
        $user = User::factory()->create();
        $this->csrf();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
        $this->post('/login', ['email' => $user->email, 'password' => 'password', '_token' => 'incorrecto'])->assertStatus(419);
        $this->assertSame(0, AuthSession::query()->where('user_id', $user->id)->count());
    }

    public function test_invalid_login_does_not_flash_the_password_or_authenticate(): void
    {
        $user = User::factory()->create();
        $csrf = $this->csrf();
        $response = $this->from('/login')->post('/login', [
            'email' => $user->email, 'password' => 'contraseña-incorrecta', '_token' => $csrf,
        ])->assertRedirect('/login')->assertSessionHasErrors('email');
        $response->assertSessionMissing('_old_input.password');
        $this->assertNull($response->getCookie(config('security.access_cookie')));
        $this->get('/login')->assertDontSee('contraseña-incorrecta');
        $this->getJson('/profile')->assertUnauthorized();
    }

    public function test_unverified_login_is_limited_to_verification_and_profile(): void
    {
        $user = User::factory()->unverified()->create();
        $this->signIn($user)->assertRedirect(route('verification.notice'));
        $this->get('/profile')->assertOk();
        $this->get('/verify-email')->assertOk();
        $this->get('/organizations')->assertRedirect(route('verification.notice'));
        $this->getJson('/organizations')->assertForbidden();
    }

    public function test_expired_access_renews_using_refresh_and_real_csrf(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $oldAccess = $this->defaultCookies[config('security.access_cookie')];
        $oldRefresh = $this->defaultCookies[config('security.refresh_cookie')];
        $sessionId = session('auth_session_id');
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();
        $this->get('/profile')->assertRedirect(route('auth.renew'));
        $response = $this->post('/auth/refresh', ['_token' => $this->csrf('/auth/renew')])
            ->assertRedirect(route('organizations.index'));
        $this->rememberCookies($response);

        $this->assertSame($sessionId, session('auth_session_id'));
        $this->assertTrue($oldAccess !== $this->defaultCookies[config('security.access_cookie')]);
        $this->assertTrue($oldRefresh !== $this->defaultCookies[config('security.refresh_cookie')]);
        $this->assertNotNull(RefreshToken::query()->where('token_hash', hash('sha256', $oldRefresh))->sole()->consumed_at);
        $this->get('/profile')->assertOk();
    }

    public function test_refresh_can_recover_after_browser_navigation_session_is_lost(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $sessionId = session('auth_session_id');
        session()->invalidate();
        session()->regenerateToken();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->assertNull(session('auth_session_id'));
        $response = $this->post('/auth/refresh', ['_token' => $this->csrf('/auth/renew')])
            ->assertRedirect(route('organizations.index'));
        $this->rememberCookies($response);

        $this->assertSame($sessionId, session('auth_session_id'));
        $this->get('/profile')->assertOk();
    }

    public function test_refresh_and_logout_reject_missing_csrf_without_mutating_the_family(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $session = AuthSession::query()->findOrFail(session('auth_session_id'));
        $this->post('/auth/refresh')->assertStatus(419);
        $this->post('/logout')->assertStatus(419);

        $this->assertNull($session->refresh()->revoked_at);
        $this->assertSame(1, RefreshToken::query()->where('auth_session_id', $session->id)->whereNull('consumed_at')->count());
        $this->get('/profile')->assertOk();
    }

    public function test_refresh_reuse_over_http_revokes_the_current_access(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $previous = $this->defaultCookies[config('security.refresh_cookie')];
        $sessionId = session('auth_session_id');
        $response = $this->post('/auth/refresh', ['_token' => $this->csrf('/auth/renew')])->assertRedirect();
        $this->rememberCookies($response);
        $this->withCookie(config('security.refresh_cookie'), $previous);
        $this->postJson('/auth/refresh', ['_token' => $this->csrf('/auth/renew')])->assertUnauthorized();

        $this->assertSame('refresh_reuse', AuthSession::query()->findOrFail($sessionId)->revocation_reason);
        $this->getJson('/profile')->assertUnauthorized();
    }

    public function test_get_requests_cannot_refresh_or_logout(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $sessionId = session('auth_session_id');
        $this->get('/auth/renew')->assertOk();
        $this->get('/auth/refresh')->assertStatus(405);
        $this->get('/logout')->assertStatus(405);

        $this->assertNull(AuthSession::query()->findOrFail($sessionId)->revoked_at);
        $this->assertSame(1, RefreshToken::query()->where('auth_session_id', $sessionId)->count());
    }

    public function test_logout_revokes_a_valid_access_even_without_a_refresh_cookie(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $access = $this->defaultCookies[config('security.access_cookie')];
        $sessionId = session('auth_session_id');
        unset($this->defaultCookies[config('security.refresh_cookie')]);
        $response = $this->post('/logout', ['_token' => $this->csrf()])->assertRedirect(route('login'));

        $this->assertSame('logout', AuthSession::query()->findOrFail($sessionId)->revocation_reason);
        $response->assertCookieExpired(config('security.access_cookie'))->assertCookieExpired(config('security.refresh_cookie'));
        $this->rememberCookies($response);
        $this->withCookie(config('security.access_cookie'), $access)->getJson('/profile')->assertUnauthorized();
    }

    public function test_logout_with_expired_access_revokes_by_refresh_and_clears_browser_state(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $sessionId = session('auth_session_id');
        $this->withSession(['active_organization_id' => '01950000-0000-7000-8000-000000000001']);
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();
        $response = $this->post('/logout', ['_token' => $this->csrf('/auth/renew')])->assertRedirect(route('login'));

        $this->assertSame('logout', AuthSession::query()->findOrFail($sessionId)->revocation_reason);
        $response->assertSessionMissing('auth_session_id')->assertSessionMissing('active_organization_id');
        $response->assertCookieExpired(config('security.access_cookie'))->assertCookieExpired(config('security.refresh_cookie'));
        $this->rememberCookies($response);
        $this->getJson('/profile')->assertUnauthorized();
    }

    public function test_a_different_signed_identity_cannot_inherit_the_previous_tenant_selection(): void
    {
        $organization = $this->pilot();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->member($first, $organization);
        $this->signIn($first);
        $this->withSession(['active_organization_id' => $organization->id]);
        $pair = app(AuthService::class)->login($second->email, 'password');
        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->get('/profile')->assertOk()->assertSee($second->email)
            ->assertSessionMissing('active_organization_id')
            ->assertSessionHas('auth_session_id', $pair->session->id);
    }

    public function test_login_rate_limit_applies_to_case_variants_of_the_same_account(): void
    {
        config(['security.login_rate_limit' => 2]);
        $user = User::factory()->create();
        $csrf = $this->csrf();
        foreach ([$user->email, strtoupper($user->email)] as $email) {
            $this->postJson('/login', ['email' => $email, 'password' => 'incorrecta', '_token' => $csrf])
                ->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password', '_token' => $csrf])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, AuthSession::query()->where('user_id', $user->id)->count());
    }

    public function test_refresh_rate_limit_does_not_consume_the_next_valid_token(): void
    {
        config(['security.refresh_rate_limit' => 2]);
        $user = User::factory()->create();
        $this->signIn($user);
        $sessionId = session('auth_session_id');

        for ($i = 0; $i < 2; $i++) {
            $response = $this->post('/auth/refresh', ['_token' => $this->csrf('/auth/renew')])->assertRedirect();
            $this->rememberCookies($response);
        }

        $current = $this->defaultCookies[config('security.refresh_cookie')];
        $this->postJson('/auth/refresh', ['_token' => $this->csrf('/auth/renew')])->assertStatus(429);
        $this->assertNull(RefreshToken::query()->where('token_hash', hash('sha256', $current))->sole()->consumed_at);
        $this->assertNull(AuthSession::query()->findOrFail($sessionId)->revoked_at);
    }

    private function signIn(User $user): TestResponse
    {
        $response = $this->post('/login', [
            'email' => $user->email, 'password' => 'password', '_token' => $this->csrf(),
        ])->assertRedirect();
        $this->rememberCookies($response);

        return $response;
    }

    private function csrf(string $path = '/login'): string
    {
        $response = $this->get($path)->assertOk();
        $this->rememberCookies($response);
        preg_match('/name="_token"\s+value="([^"]+)"/', (string) $response->getContent(), $match);
        $this->assertArrayHasKey(1, $match, 'El formulario debe incluir un token CSRF real.');

        return html_entity_decode($match[1], ENT_QUOTES);
    }

    /** Reproduce el envío de cookies del navegador sin exponerlas en salida o argumentos. */
    private function rememberCookies(TestResponse $response): void
    {
        foreach ([config('session.cookie'), config('security.access_cookie'), config('security.refresh_cookie')] as $name) {
            $cookie = $response->getCookie($name);
            if ($cookie === null) {
                continue;
            }
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
                unset($this->defaultCookies[$name]);
                continue;
            }
            $this->withCookie($name, (string) $cookie->getValue());
        }
    }
}
