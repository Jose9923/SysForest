<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSecurityMail;
use App\Mail\SecurityNotification;
use App\Models\AccountActionToken;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\PasswordService;
use App\Services\SecurityMailService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use RuntimeException;
use Tests\PostgresTestCase;

class PasswordSecurityTest extends PostgresTestCase
{
    private User $user;
    private PasswordService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Bus::fake([SendSecurityMail::class]);
        $this->user = User::factory()->create(['email' => 'password@sysforest.test', 'active' => true, 'password' => 'AnteriorSegura!2026']);
        $this->service = app(PasswordService::class);
    }

    public function test_reset_stores_only_a_hash_and_revokes_every_existing_session(): void
    {
        $sessions = [$this->createAuthSession(), $this->createAuthSession()];
        $this->service->requestReset(' PASSWORD@SYSFOREST.TEST ');
        $token = $this->sentToken();
        $stored = AccountActionToken::query()->where('user_id', $this->user->id)->where('purpose', 'password_reset')->sole();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertStringNotContainsString($token, $stored->toJson());
        $this->service->reset($token, 'NuevaClave!2026');
        $this->assertTrue(Hash::check('NuevaClave!2026', $this->user->fresh()->password));
        $this->assertNotNull($stored->fresh()->consumed_at);
        foreach ($sessions as $session) {
            $this->assertNotNull($session->fresh()->revoked_at);
            $this->assertSame('password_reset', $session->fresh()->revocation_reason);
        }
    }

    public function test_nonexistent_and_inactive_accounts_do_not_queue_recovery_mail(): void
    {
        $this->service->requestReset('inexistente@sysforest.test');
        $this->user->forceFill(['active' => false])->save();
        $this->service->requestReset($this->user->email);
        Bus::assertNotDispatched(SendSecurityMail::class);
        $this->assertSame(0, AccountActionToken::query()->where('user_id', $this->user->id)->count());
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $this->service->requestReset($this->user->email);
        $token = $this->sentToken();
        $this->service->reset($token, 'NuevaClave!2026');
        $this->expectException(ValidationException::class);
        $this->service->reset($token, 'OtraClave!2026');
    }

    public function test_invalid_token_leaves_password_and_session_unchanged(): void
    {
        $session = $this->createAuthSession();
        $hash = $this->user->password;
        try {
            $this->service->reset(str_repeat('a', 64), 'NuevaClave!2026');
            $this->fail('Se aceptó un token inexistente.');
        } catch (ValidationException) {
            $this->assertSame($hash, $this->user->fresh()->password);
            $this->assertNull($session->fresh()->revoked_at);
        }
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $this->service->requestReset($this->user->email);
        $token = $this->sentToken();
        $this->travel(61)->minutes();
        $this->expectException(ValidationException::class);
        $this->service->reset($token, 'NuevaClave!2026');
    }

    public function test_new_reset_request_invalidates_the_previous_link(): void
    {
        $this->service->requestReset($this->user->email);
        $oldToken = $this->sentToken();
        $this->service->requestReset($this->user->email);
        $this->expectException(ValidationException::class);
        $this->service->reset($oldToken, 'NuevaClave!2026');
    }

    public function test_email_change_invalidates_a_previously_issued_reset_token(): void
    {
        $this->service->requestReset($this->user->email);
        $token = $this->sentToken();
        $this->user->forceFill(['email' => 'otra@sysforest.test'])->save();
        $this->expectException(ValidationException::class);
        $this->service->reset($token, 'NuevaClave!2026');
    }

    public function test_password_change_checks_current_password_and_revokes_all_sessions(): void
    {
        $sessions = [$this->createAuthSession(), $this->createAuthSession()];
        $this->service->change($this->user, 'AnteriorSegura!2026', 'NuevaClave!2026');
        $this->assertTrue(Hash::check('NuevaClave!2026', $this->user->fresh()->password));
        foreach ($sessions as $session) {
            $this->assertNotNull($session->fresh()->revoked_at);
            $this->assertSame('password_changed', $session->fresh()->revocation_reason);
        }
    }

    public function test_wrong_current_password_does_not_change_account_or_revoke_sessions(): void
    {
        $session = $this->createAuthSession();
        $hash = $this->user->password;
        try {
            $this->service->change($this->user, 'incorrecta', 'NuevaClave!2026');
            $this->fail('No se comprobó la contraseña actual.');
        } catch (ValidationException) {
            $this->assertSame($hash, $this->user->fresh()->password);
            $this->assertNull($session->fresh()->revoked_at);
        }
    }

    public function test_weak_password_cannot_consume_reset_token(): void
    {
        $this->service->requestReset($this->user->email);
        $token = $this->sentToken();
        try {
            $this->service->reset($token, 'minusculaslargas');
            $this->fail('Se aceptó una contraseña débil.');
        } catch (ValidationException) {
            $this->assertNull(AccountActionToken::query()->where('token_hash', hash('sha256', $token))->sole()->consumed_at);
        }
    }

    public function test_mail_job_payload_is_encrypted_and_committed_after_account_transaction(): void
    {
        $token = bin2hex(random_bytes(32));
        $job = new SendSecurityMail('password_reset', $this->user->email, ['action_url' => 'http://localhost/reset-password?token='.$token]);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertTrue($job->afterCommit);
        // Inspecciona el payload real de Laravel sin enviarlo a Redis ni ejecutar el job.
        $method = new ReflectionMethod(Queue::class, 'createPayload');
        $payload = $method->invoke(app('queue')->connection('redis'), $job, 'default');
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString($this->user->email, $payload);
        $this->assertNotEmpty(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
    }

    public function test_token_mail_is_rejected_when_log_transport_is_configured(): void
    {
        Mail::clearResolvedInstance('mail.manager');
        app()->forgetInstance('mail.manager');
        config(['mail.default' => 'log']);
        $this->expectException(RuntimeException::class);
        app(SecurityMailService::class)->send('verification', $this->user, ['token' => bin2hex(random_bytes(32))]);
    }

    public function test_all_mail_kinds_render_the_institutional_identity_and_escape_user_text(): void
    {
        foreach (['invitation', 'welcome', 'verification', 'password_reset', 'account_activated', 'account_deactivated', 'membership_activated', 'membership_deactivated'] as $kind) {
            $mail = new SecurityNotification($kind, [
                'name' => '<script>alert(1)</script>', 'organization' => 'INEM', 'role' => 'OFFICIAL',
                'action_url' => 'http://localhost/login', 'expires_minutes' => 60,
            ]);
            $html = $mail->render();
            $this->assertStringContainsString('SYSFOREST', $html);
            $this->assertStringContainsString('Inventario y gestión forestal inteligente', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    private function createAuthSession(): AuthSession
    {
        return AuthSession::query()->create(['user_id' => $this->user->id, 'expires_at' => now()->addDay(), 'last_used_at' => now()]);
    }

    private function sentToken(): string
    {
        $token = '';
        Bus::assertDispatched(SendSecurityMail::class, function (SendSecurityMail $job) use (&$token): bool {
            if ($job->kind !== 'password_reset') {
                return false;
            }
            parse_str((string) parse_url($job->data['action_url'], PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return $token;
    }
}
