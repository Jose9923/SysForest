<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSecurityMail;
use App\Models\AccountActionToken;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Services\InvitationService;
use App\Services\SessionService;
use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\PostgresTestCase;

class IdentityLinkHttpTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Bus::fake([SendSecurityMail::class]);
    }

    public function test_invalid_access_does_not_lose_the_invitation_return_link(): void
    {
        $recipient = User::factory()->create();
        [$invitation, $token] = $this->invitationFor($recipient->email);

        $this->withCookie(config('security.access_cookie'), 'credencial-invalida')
            ->withCookie(config('security.refresh_cookie'), 'refresh-invalido')
            ->get('/invitations/accept?token='.$token)
            ->assertOk()
            ->assertSee('Iniciar sesión con mi cuenta')
            ->assertSessionHas('pending_invitation', $token)
            ->assertCookieExpired(config('security.access_cookie'))
            ->assertCookieExpired(config('security.refresh_cookie'));

        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertNull(Auth::guard('web')->user());
        $this->post('/login', ['email' => $recipient->email, 'password' => 'password'])
            ->assertRedirect(route('invitations.preview', ['token' => $token]));
    }

    public function test_revoked_session_is_anonymous_on_a_verification_link(): void
    {
        $recipient = User::factory()->unverified()->create();
        app(EmailVerificationService::class)->send($recipient);
        $token = $this->sentToken('verification');
        $pair = app(AuthService::class)->login($recipient->email, 'password');
        app(SessionService::class)->revoke($pair->session, 'user_revoked');

        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->get('/verify-email?token='.$token)
            ->assertOk()
            ->assertSee('Iniciar sesión para verificar mi correo')
            ->assertSessionHas('pending_verification', $token)
            ->assertCookieExpired(config('security.access_cookie'));

        $this->assertNull($recipient->fresh()->email_verified_at);
        $this->assertNull(AccountActionToken::query()->where('token_hash', hash('sha256', $token))->sole()->consumed_at);
        $this->post('/login', ['email' => $recipient->email, 'password' => 'password'])
            ->assertRedirect(route('verification.notice', ['token' => $token]));
    }

    public function test_expired_access_does_not_block_a_public_invitation(): void
    {
        $recipient = User::factory()->create();
        [, $token] = $this->invitationFor($recipient->email);
        $pair = app(AuthService::class)->login($recipient->email, 'password');
        $this->travel((int) config('security.access_ttl_minutes') + 2)->minutes();

        $this->withCookie(config('security.access_cookie'), $pair->accessToken)
            ->get('/invitations/accept?token='.$token)
            ->assertOk()
            ->assertSessionHas('pending_invitation', $token)
            ->assertCookieExpired(config('security.access_cookie'));
    }

    public function test_optional_authentication_never_repeats_a_controller_that_throws(): void
    {
        $calls = 0;
        Route::middleware(['web', 'jwt.optional'])->get('/__tests/optional-once', function () use (&$calls): never {
            $calls++;
            throw new AuthenticationException('No fue posible autenticar la solicitud.');
        });

        $this->withCookie(config('security.access_cookie'), 'credencial-invalida')
            ->withCredentials()
            ->getJson('/__tests/optional-once')
            ->assertUnauthorized();

        $this->assertSame(1, $calls);
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_another_signed_in_account_must_sign_out_before_creating_an_invited_account(): void
    {
        [$invitation, $token] = $this->invitationFor('persona-nueva@sysforest.test');
        $other = User::factory()->create();

        $this->actingAs($other)->get('/invitations/accept?token='.$token)
            ->assertOk()
            ->assertViewHas('differentAuthenticatedUser', true)
            ->assertSee('Cerrar sesión para continuar')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('Aceptar invitación</button>', false);

        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseMissing('users', ['email' => 'persona-nueva@sysforest.test']);
    }

    public function test_query_arrays_render_an_invalid_link_instead_of_a_server_error(): void
    {
        foreach (['/invitations/accept', '/reset-password', '/verify-email'] as $path) {
            $this->get($path.'?token%5B%5D=contenido-no-valido')
                ->assertOk()
                ->assertSee('Este enlace no está disponible')
                ->assertDontSee('contenido-no-valido');
        }
        $this->assertNull(session('pending_invitation'));
        $this->assertNull(session('pending_verification'));
    }

    public function test_authenticated_verification_requires_post_and_consumes_the_link_once(): void
    {
        $recipient = User::factory()->unverified()->create();
        app(EmailVerificationService::class)->send($recipient);
        $token = $this->sentToken('verification');
        $record = AccountActionToken::query()->where('token_hash', hash('sha256', $token))->sole();

        $this->actingAs($recipient)->get('/verify-email?token='.$token)
            ->assertOk()->assertSee('Confirmar correo electrónico');
        $this->assertNull($recipient->fresh()->email_verified_at);
        $this->assertNull($record->fresh()->consumed_at);

        $this->post('/verify-email', ['token' => $token])->assertRedirect(route('organizations.index'));
        $this->assertNotNull($recipient->fresh()->email_verified_at);
        $this->assertNotNull($record->fresh()->consumed_at);
        $this->postJson('/verify-email', ['token' => $token])->assertUnprocessable();
    }

    /** @return array{Invitation, string} */
    private function invitationFor(string $email): array
    {
        $organization = $this->pilot();
        $admin = User::factory()->create();
        $this->member($admin, $organization);
        $this->allowManagement($admin);
        app(TenantContextService::class)->activate($admin, $organization->id);
        try {
            $invitation = app(InvitationService::class)->create($admin, ['email' => $email, 'role' => 'OFFICIAL']);
        } finally {
            app(TenantContext::class)->clear();
        }

        return [$invitation, $this->sentToken('invitation')];
    }

    private function sentToken(string $kind): string
    {
        $token = '';
        Bus::assertDispatched(SendSecurityMail::class, function (SendSecurityMail $job) use ($kind, &$token): bool {
            if ($job->kind !== $kind) {
                return false;
            }
            parse_str((string) parse_url($job->data['action_url'], PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return $token;
    }
}
