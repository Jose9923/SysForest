<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\TokenPair;
use App\Exceptions\SessionContinuityException;
use App\Models\AuthSession;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AuthService;
use App\Services\RefreshTokenService;
use App\Services\SessionContinuityService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\PostgresTestCase;

class SessionContinuityTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_status_is_read_only_and_never_returns_authentication_tokens(): void
    {
        $pair = $this->signIn();
        $context = $this->context();
        $response = $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('state', 'ready');

        $response->assertJsonPath('context', $context)->assertJsonPath('csrf_token', session()->token());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertNull($response->getCookie(config('security.access_cookie')));
        $this->assertNull($response->getCookie(config('security.refresh_cookie')));
        foreach ([$pair->accessToken, $pair->refreshToken, $pair->session->id, (string) $pair->user->email] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame(1, $this->tokenCount($pair));
        $this->assertNull($pair->session->refresh()->revoked_at);
    }

    public function test_expired_access_renews_without_losing_csrf_navigation_session_or_tenant(): void
    {
        $pair = $this->signIn();
        $tenant = (string) Str::uuid();
        $this->withSession(['active_organization_id' => $tenant, 'form_marker' => 'conservado']);
        session()->save();
        $context = $this->context();
        $sessionId = session()->getId();
        $csrf = session()->token();
        $expiration = $pair->session->expires_at->getTimestamp();
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();

        $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('state', 'renewal_required');
        $response = $this->postJson('/auth/session/ensure', ['expected_context' => $context, '_token' => $csrf])
            ->assertOk()->assertJsonPath('state', 'ready')->assertJsonPath('context', $context);
        $this->rememberCookies($response);

        $this->assertSame($sessionId, session()->getId());
        $this->assertSame($csrf, session()->token());
        $response->assertSessionHas('active_organization_id', $tenant)->assertSessionHas('form_marker', 'conservado');
        $this->assertSame($expiration, $pair->session->refresh()->expires_at->getTimestamp());
        $this->assertSame(2, $this->tokenCount($pair));
        $this->assertNotSame($pair->accessToken, $this->defaultCookies[config('security.access_cookie')]);
        $this->assertNotSame($pair->refreshToken, $this->defaultCookies[config('security.refresh_cookie')]);
        foreach (['access_cookie', 'refresh_cookie'] as $name) {
            $this->assertTrue($response->getCookie(config('security.'.$name))->isHttpOnly());
        }
        $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('state', 'ready');
    }

    public function test_browser_can_remove_expired_access_cookie_before_silent_renewal(): void
    {
        $pair = $this->signIn();
        unset($this->defaultCookies[config('security.access_cookie')]);
        $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('access_expires_at', null);
        $this->ensure()->assertOk()->assertJsonPath('state', 'ready');
        $this->assertSame(2, $this->tokenCount($pair));
    }

    public function test_original_form_can_be_submitted_once_after_access_expiration(): void
    {
        $pair = $this->signIn();
        $form = [
            'name' => 'Nombre conservado del formulario',
            '_token' => session()->token(), '_session_context' => $this->context(),
        ];
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();
        $this->rememberCookies($this->ensure()->assertOk());

        $this->from('/profile')->put('/profile', $form)->assertRedirect('/profile')
            ->assertSessionHas('status', 'Perfil actualizado.');
        $this->assertSame($form['name'], $pair->user->fresh()->name);
        $this->assertSame(2, $this->tokenCount($pair));
    }

    public function test_fresh_access_does_not_rotate_even_when_multiple_tabs_preflight(): void
    {
        $pair = $this->signIn();
        for ($index = 0; $index < 3; $index++) {
            $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('state', 'ready');
            $response = $this->ensure()->assertOk()->assertJsonPath('state', 'ready');
            $this->assertNull($response->getCookie(config('security.refresh_cookie')));
        }
        $this->assertSame(1, $this->tokenCount($pair));
        $this->assertNull($pair->session->refresh()->revoked_at);
    }

    public function test_refresh_margin_is_adaptive_and_absolute_expiration_never_extends(): void
    {
        config(['security.access_ttl_minutes' => 1, 'security.session_ttl_minutes' => 1]);
        $pair = $this->signIn();
        $this->travel(45)->seconds();
        $this->getJson('/auth/session/status')->assertOk()
            ->assertJsonPath('state', 'ready')->assertJsonPath('renew_before_seconds', 20);
        $this->ensure()->assertOk();
        $this->assertSame(1, $this->tokenCount($pair));
        $this->travelTo($pair->session->expires_at);
        $this->getJson('/auth/session/status')->assertUnauthorized()->assertJsonPath('state', 'ended');
        $this->ensure()->assertUnauthorized()->assertJsonPath('state', 'ended');
        $this->assertSame(1, $this->tokenCount($pair));
    }

    public function test_access_approaching_expiration_rotates_once_and_the_next_tab_observes_new_cookies(): void
    {
        config(['security.access_ttl_minutes' => 1]);
        $pair = $this->signIn();
        $this->travel(41)->seconds();
        $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('state', 'renewal_required');
        $this->rememberCookies($this->ensure()->assertOk()->assertJsonPath('state', 'ready'));
        $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('state', 'ready');
        $this->ensure()->assertOk();
        $this->assertSame(2, $this->tokenCount($pair));
    }

    public function test_silent_renewal_requires_real_csrf_and_get_cannot_rotate(): void
    {
        $pair = $this->signIn();
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();
        $this->postJson('/auth/session/ensure', ['expected_context' => $this->context()])->assertStatus(419);
        $this->postJson('/auth/session/ensure', ['expected_context' => $this->context(), '_token' => 'incorrecto'])->assertStatus(419);
        $this->getJson('/auth/session/ensure')->assertStatus(405);
        $this->assertSame(1, $this->tokenCount($pair));
        $this->assertNull($pair->session->refresh()->revoked_at);
    }

    public function test_missing_navigation_binding_cannot_silently_restore_a_tenant(): void
    {
        $pair = $this->signIn();
        session()->forget('auth_session_id');
        session()->save();
        $this->getJson('/auth/session/status')->assertConflict()->assertJsonPath('state', 'context_missing');
        $this->ensure()->assertConflict()->assertJsonPath('state', 'context_missing');
        $this->assertSame(1, $this->tokenCount($pair));
    }

    public function test_different_family_cookie_cannot_inherit_navigation_binding(): void
    {
        $first = $this->signIn();
        $secondUser = User::factory()->create();
        $second = app(AuthService::class)->login($secondUser->email, 'password');
        $this->withCookie(config('security.access_cookie'), $second->accessToken)
            ->withCookie(config('security.refresh_cookie'), $second->refreshToken);
        $this->getJson('/auth/session/status')->assertConflict()->assertJsonPath('state', 'context_changed');
        $this->ensure()->assertConflict()->assertJsonPath('state', 'context_changed');
        $this->assertSame(1, $this->tokenCount($first));
        $this->assertSame(1, $this->tokenCount($second));
        $this->assertSame($first->session->id, session('auth_session_id'));
    }

    public function test_refresh_from_another_family_is_not_consumed_when_access_expires(): void
    {
        $first = $this->signIn();
        $second = app(AuthService::class)->login($first->user->email, 'password');
        $this->withCookie(config('security.refresh_cookie'), $second->refreshToken);
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();
        $this->ensure()->assertConflict()->assertJsonPath('state', 'context_changed');
        $this->assertSame(1, $this->tokenCount($first));
        $this->assertSame(1, $this->tokenCount($second));
    }

    public function test_status_does_not_revoke_consumed_refresh_but_post_retains_reuse_detection(): void
    {
        $pair = $this->signIn();
        app(RefreshTokenService::class)->rotate($pair->refreshToken);
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();
        $this->getJson('/auth/session/status')->assertUnauthorized()->assertJsonPath('state', 'ended');
        $this->assertNull($pair->session->refresh()->revoked_at);
        $this->ensure()->assertUnauthorized()->assertJsonPath('state', 'ended');
        $this->assertSame('refresh_reuse', $pair->session->refresh()->revocation_reason);
    }

    public function test_revoked_or_disabled_sessions_cannot_be_kept_alive(): void
    {
        $pair = $this->signIn();
        $pair->session->forceFill(['revoked_at' => now(), 'revocation_reason' => 'test'])->save();
        $this->getJson('/auth/session/status')->assertUnauthorized()->assertJsonPath('state', 'ended');
        $this->ensure()->assertUnauthorized();
        $this->assertSame(1, $this->tokenCount($pair));

        $pair = $this->signIn();
        $pair->user->forceFill(['active' => false])->save();
        $this->getJson('/auth/session/status')->assertUnauthorized();
        $this->ensure()->assertUnauthorized();
        $this->assertSame(1, $this->tokenCount($pair));
    }

    public function test_old_form_context_is_rejected_before_profile_mutation(): void
    {
        $pair = $this->signIn();
        $previous = $this->context();
        $this->withSession(['active_organization_id' => (string) Str::uuid()]);
        session()->save();
        $response = $this->putJson('/profile', [
            'name' => 'Cambio rechazado',
            '_session_context' => $previous, '_token' => session()->token(),
        ])->assertConflict()->assertJsonPath('state', 'context_changed');
        $this->assertSame($pair->user->name, $pair->user->fresh()->name);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->postJson('/auth/session/ensure', [
            'expected_context' => $previous, '_token' => session()->token(),
        ])->assertConflict()->assertJsonPath('state', 'context_changed');
    }

    public function test_context_header_is_checked_for_ajax_and_bootstrap_is_shared_without_secrets(): void
    {
        $pair = $this->signIn();
        $this->withHeader('X-Session-Context', str_repeat('0', 64))
            ->getJson('/profile')->assertConflict()->assertJsonPath('state', 'context_changed');
        $this->flushHeaders();
        $response = $this->get('/profile')->assertOk()->assertSee('id="session-continuity-config"', false);
        $boot = $this->bootstrap($response);
        $this->assertSame($this->context(), $boot['context']);
        $this->assertSame(route('auth.session.ensure'), $boot['ensure_url']);
        $this->assertSame(session()->token(), $boot['csrf_token']);
        $this->assertStringNotContainsString($pair->accessToken, json_encode($boot, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($pair->refreshToken, json_encode($boot, JSON_THROW_ON_ERROR));
    }

    public function test_bootstrap_uses_final_tenant_context_after_membership_revocation(): void
    {
        $pair = $this->signIn();
        $organization = $this->pilot();
        $membership = $this->member($pair->user, $organization);
        $this->withSession(['active_organization_id' => $organization->id]);
        session()->save();
        $previous = $this->context();
        $membership->forceFill(['active' => false])->save();

        $response = $this->get('/profile')->assertOk()->assertSessionMissing('active_organization_id');
        $boot = $this->bootstrap($response);
        $this->assertNotSame($previous, $boot['context']);
        $this->assertSame($this->context(), $boot['context']);
        $this->getJson('/auth/session/status')->assertOk()->assertJsonPath('context', $boot['context']);
    }

    public function test_expected_session_failures_are_not_reported_as_application_errors(): void
    {
        $handler = app(ExceptionHandler::class);
        foreach ([401, 409] as $status) {
            $this->assertFalse($handler->shouldReport(new SessionContinuityException('ended', 'Sesión finalizada.', $status)));
        }
    }

    public function test_background_requests_preserve_validation_errors_and_input_until_a_page_consumes_them(): void
    {
        $this->signIn();
        $invalidName = str_repeat('N', 151);
        $this->from('/profile')->put('/profile', [
            'name' => $invalidName, '_token' => session()->token(),
        ])->assertRedirect('/profile')->assertSessionHasErrors('name');
        $this->travel((int) config('security.access_ttl_minutes') + 1)->minutes();

        $this->getJson('/auth/session/status', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('state', 'renewal_required');
        $response = $this->postJson('/auth/session/ensure', [
            'expected_context' => $this->context(), '_token' => session()->token(),
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->rememberCookies($response);

        $this->get('/profile')->assertOk()->assertSee($invalidName)
            ->assertSee('El campo nombre no debe superar 150 caracteres.');
        $this->get('/profile')->assertOk()->assertDontSee($invalidName)
            ->assertDontSee('El campo nombre no debe superar 150 caracteres.');
    }

    public function test_ajax_session_checks_preserve_return_url_and_success_notice(): void
    {
        $this->signIn();
        $this->get('/profile')->assertOk();
        $this->getJson('/auth/session/status', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertSame(route('profile.show'), session()->previousUrl());

        // Sin Referer, back() debe volver al formulario real y no al endpoint de consulta de sesión.
        $this->put('/profile', [
            'name' => 'Aviso conservado', '_token' => session()->token(),
        ])->assertRedirect(route('profile.show'))->assertSessionHas('status', 'Perfil actualizado.');
        $this->getJson('/auth/session/status', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->postJson('/auth/session/ensure', [
            'expected_context' => $this->context(), '_token' => session()->token(),
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->get('/profile')->assertOk()->assertSee('Perfil actualizado.');
        $this->get('/profile')->assertOk()->assertDontSee('Perfil actualizado.');
    }

    private function bootstrap(TestResponse $response): array
    {
        preg_match('/<script\\b[^>]*\\bid="session-continuity-config"[^>]*>(.*?)<\/script>/s', $response->getContent(), $match);
        $this->assertArrayHasKey(1, $match, 'La configuración debe estar disponible durante el renderizado Blade.');

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function signIn(): TokenPair
    {
        $user = User::factory()->create();
        // El fixture recorre login y conserva todas las cookies HTTP, como el navegador.
        $this->rememberCookies($this->get('/login')->assertOk());
        $this->rememberCookies($this->post('/login', [
            'email' => $user->email, 'password' => 'password', '_token' => session()->token(),
        ])->assertRedirect(route('organizations.index')));

        return new TokenPair(
            $this->defaultCookies[config('security.access_cookie')],
            $this->defaultCookies[config('security.refresh_cookie')],
            AuthSession::query()->findOrFail(session('auth_session_id')),
            $user,
        );
    }

    private function context(): string
    {
        $request = request();
        $request->setLaravelSession(session()->driver());

        return app(SessionContinuityService::class)->context($request);
    }

    private function ensure(): TestResponse
    {
        return $this->postJson('/auth/session/ensure', [
            'expected_context' => $this->context(), '_token' => session()->token(),
        ]);
    }

    private function tokenCount(TokenPair $pair): int
    {
        return RefreshToken::query()->where('auth_session_id', $pair->session->id)->count();
    }

    private function rememberCookies(TestResponse $response): void
    {
        foreach ([config('session.cookie'), config('security.access_cookie'), config('security.refresh_cookie')] as $name) {
            if (($cookie = $response->getCookie($name)) !== null) {
                $this->withCookie($name, (string) $cookie->getValue());
            }
        }
    }
}
