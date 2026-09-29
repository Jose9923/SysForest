<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StaleInspectionException;
use App\Http\SystemExceptionRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** No usa base de datos: prueba también el respaldo cuando la infraestructura no responde. */
class SystemErrorSecurityTest extends TestCase
{
    private const DETAIL = 'SQLSTATE sensitive-query /var/www/html/private.php secret-sentinel';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true, 'session.driver' => 'array', 'cache.default' => 'array']);
    }

    #[DataProvider('uncoveredPaths')]
    public function test_unexpected_failures_never_return_debug_details_in_html_or_json(string $path): void
    {
        Route::middleware(['web', 'identity.headers'])->get($path, static function (): never {
            throw new RuntimeException(self::DETAIL);
        });

        foreach ([$this->get($path), $this->getJson($path)] as $response) {
            $response->assertStatus(500)->assertDontSee('SQLSTATE')->assertDontSee('private.php')
                ->assertDontSee('secret-sentinel')->assertDontSee('RuntimeException');
            $this->assertSafeHeaders($response);
        }
        $this->getJson($path)->assertExactJson([
            'message' => 'No fue posible completar la operación. Inténtalo nuevamente en unos momentos.',
        ]);
    }

    public static function uncoveredPaths(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '/login', '/profile', '/trees', '/inspections', '/inventories', '/catalogs', '/organizations', '/users',
        ]);
    }

    public function test_api_path_remains_json_without_an_accept_header(): void
    {
        Route::get('/api/__tests/security', static function (): never {
            throw new RuntimeException(self::DETAIL);
        });
        $response = $this->get('/api/__tests/security')->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')->assertDontSee('secret-sentinel');
        $this->assertSafeHeaders($response);
    }

    #[DataProvider('controlledFailures')]
    public function test_http_errors_hide_their_original_message_and_keep_status(string $kind, int $status): void
    {
        Route::middleware('web')->get('/__tests/security/controlled', static function () use ($kind): never {
            throw match ($kind) {
                'forbidden' => new AuthorizationException(self::DETAIL),
                'missing' => (new ModelNotFoundException())->setModel('SecretModel', ['private-id']),
                'csrf' => new TokenMismatchException(self::DETAIL),
                'method' => new HttpException(405, self::DETAIL, null, ['Allow' => 'GET']),
                'throttle' => new HttpException(429, self::DETAIL, null, ['Retry-After' => '60']),
                default => new HttpException(503, self::DETAIL),
            };
        });
        foreach ([$this->get('/__tests/security/controlled'), $this->getJson('/__tests/security/controlled')] as $response) {
            $response->assertStatus($status)->assertDontSee('SQLSTATE')->assertDontSee('secret-sentinel')
                ->assertDontSee('SecretModel')->assertDontSee('private-id');
            $this->assertSafeHeaders($response);
            if ($status === 405) {
                $response->assertHeader('Allow', 'GET');
            }
            if ($status === 429) {
                $response->assertHeader('Retry-After', '60');
            }
        }
    }

    public static function controlledFailures(): array
    {
        return [['forbidden', 403], ['missing', 404], ['csrf', 419], ['method', 405], ['throttle', 429], ['unavailable', 503]];
    }

    public function test_unknown_route_is_safe_before_route_middleware_runs(): void
    {
        $response = $this->get('/__missing-system-route')->assertNotFound()->assertDontSee('NotFoundHttpException');
        $this->assertSafeHeaders($response);
    }

    public function test_authentication_redirects_and_json_status_are_preserved(): void
    {
        $response = $this->get('/profile')->assertRedirect(route('login'));
        $this->assertSafeHeaders($response);
        $this->withCookie(config('security.refresh_cookie'), str_repeat('a', 64));
        $this->get('/profile')->assertRedirect(route('auth.renew'));
        $response = $this->getJson('/profile')->assertUnauthorized()->assertExactJson([
            'message' => 'La sesión no es válida. Inicia sesión nuevamente.',
        ]);
        $this->assertSafeHeaders($response);
    }

    public function test_validation_preserves_field_errors_and_does_not_flash_credentials(): void
    {
        Route::middleware('web')->post('/__tests/security/validation', static function (): never {
            throw ValidationException::withMessages(['name' => 'Revisa el nombre indicado.']);
        });
        $input = ['name' => 'Nombre conservado', 'password' => 'private-password', 'current_password' => 'private-current',
            'password_confirmation' => 'private-confirmation', 'token' => 'private-token',
            'refresh_token' => 'private-refresh', 'access_token' => 'private-access'];
        $response = $this->from('/profile')->post('/__tests/security/validation', $input)
            ->assertRedirect('/profile')->assertSessionHasErrors('name')->assertSessionHasInput('name', 'Nombre conservado');
        foreach (array_keys($input) as $key) {
            if ($key !== 'name') {
                $response->assertSessionMissing('_old_input.'.$key);
            }
        }
        $this->assertSafeHeaders($response);
        $response = $this->postJson('/__tests/security/validation', $input)->assertUnprocessable()
            ->assertJsonValidationErrors('name')->assertDontSee('private-password');
        $this->assertSafeHeaders($response);
    }

    public function test_inspection_conflict_keeps_its_specific_user_message(): void
    {
        Route::get('/__tests/security/conflict', static function (): never {
            throw new StaleInspectionException();
        });
        $this->getJson('/__tests/security/conflict')->assertConflict()->assertJsonPath(
            'message',
            'Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.'
        );
    }

    #[DataProvider('existingRenderers')]
    public function test_specialized_renderers_remain_in_effect(string $path): void
    {
        Route::middleware('web')->get($path, static function (): never {
            throw new RuntimeException(self::DETAIL);
        });
        $response = $this->getJson($path)->assertStatus(503)->assertDontSee('secret-sentinel');
        $this->assertSafeHeaders($response);
    }

    public static function existingRenderers(): array
    {
        return [['/portal/__failure'], ['/reports/__failure'], ['/imports/__failure'],
            ['/evidences/__failure'], ['/maintenances/__failure'], ['/map/__failure']];
    }

    public function test_explicit_responses_keep_cookies_and_stricter_csp(): void
    {
        $policy = "default-src 'none'; sandbox";
        Route::get('/__tests/security/response', static function () use ($policy): never {
            $response = response('Archivo no disponible', 404, ['Content-Security-Policy' => $policy]);
            $response->headers->setCookie(new Cookie('security-check', 'safe-value', 0, '/', null, true, true));
            throw new HttpResponseException($response);
        });
        $response = $this->get('/__tests/security/response')->assertNotFound()
            ->assertHeader('Content-Security-Policy', $policy)->assertCookie('security-check', 'safe-value', false);
        $this->assertSafeHeaders($response);
        $direct = app(SystemExceptionRenderer::class)->secure(response('')->header('Content-Security-Policy', $policy));
        $this->assertSame($policy, $direct->headers->get('Content-Security-Policy'));
    }

    #[DataProvider('identityForms')]
    public function test_malformed_email_reaches_validation_instead_of_breaking_the_rate_limiter(string $path): void
    {
        $response = $this->postJson($path, ['email' => ['unexpected' => 'array'], 'password' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertDontSee('Array to string conversion');
        $this->assertSafeHeaders($response);
    }

    public static function identityForms(): array
    {
        return [['/login'], ['/forgot-password']];
    }

    #[DataProvider('cacheableStatuses')]
    public function test_error_headers_do_not_override_successful_public_image_cache(int $status): void
    {
        Route::get('/__tests/security/cacheable', static fn () => response('', $status, ['Cache-Control' => 'public, max-age=60']));
        $response = $this->get('/__tests/security/cacheable')->assertStatus($status);
        $this->assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public static function cacheableStatuses(): array
    {
        return [[200], [304]];
    }

    private function assertSafeHeaders(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }
}
