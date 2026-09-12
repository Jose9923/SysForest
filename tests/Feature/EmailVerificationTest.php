<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendSecurityMail;
use App\Models\AccountActionToken;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\PostgresTestCase;

class EmailVerificationTest extends PostgresTestCase
{
    private User $user;
    private EmailVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Bus::fake([SendSecurityMail::class]);
        $this->user = User::factory()->unverified()->create(['email' => 'verificar@sysforest.test', 'active' => true]);
        $this->service = app(EmailVerificationService::class);
    }

    public function test_preview_has_no_side_effect_and_verification_consumes_the_token(): void
    {
        $this->service->send($this->user);
        $token = $this->sentToken();
        $stored = AccountActionToken::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertSame((int) $this->user->id, (int) $this->service->preview($token)->id);
        $this->assertNull($this->user->fresh()->email_verified_at);
        $this->assertNull($stored->fresh()->consumed_at);
        $this->service->verify($this->user, $token);
        $this->assertNotNull($this->user->fresh()->email_verified_at);
        $this->assertNotNull($stored->fresh()->consumed_at);
    }

    public function test_verification_token_cannot_be_reused(): void
    {
        $this->service->send($this->user);
        $token = $this->sentToken();
        $this->service->verify($this->user, $token);
        $this->expectException(ValidationException::class);
        $this->service->verify($this->user, $token);
    }

    public function test_invalid_verification_link_does_not_verify_the_user(): void
    {
        try {
            $this->service->verify($this->user, str_repeat('f', 64));
            $this->fail('Se aceptó un token inexistente.');
        } catch (ValidationException) {
            $this->assertNull($this->user->fresh()->email_verified_at);
        }
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $this->service->send($this->user);
        $token = $this->sentToken();
        $this->travel(61)->minutes();
        $this->expectException(ValidationException::class);
        $this->service->verify($this->user, $token);
    }

    public function test_different_account_cannot_verify_with_the_invited_users_link(): void
    {
        $other = User::factory()->unverified()->create(['active' => true]);
        $this->service->send($this->user);
        $this->expectException(ValidationException::class);
        $this->service->verify($other, $this->sentToken());
    }

    public function test_email_change_invalidates_the_verification_snapshot(): void
    {
        $this->service->send($this->user);
        $token = $this->sentToken();
        $this->user->forceFill(['email' => 'distinto@sysforest.test'])->save();
        $this->expectException(ValidationException::class);
        $this->service->verify($this->user, $token);
    }

    public function test_resending_verification_invalidates_the_previous_link(): void
    {
        $this->service->send($this->user);
        $oldToken = $this->sentToken();
        $this->service->send($this->user);
        $this->expectException(ValidationException::class);
        $this->service->verify($this->user, $oldToken);
    }

    public function test_verified_user_does_not_receive_another_verification_token(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->service->send($this->user);
        Bus::assertNotDispatched(SendSecurityMail::class);
        $this->assertDatabaseMissing('account_action_tokens', ['user_id' => $this->user->id]);
    }

    private function sentToken(): string
    {
        $token = '';
        Bus::assertDispatched(SendSecurityMail::class, function (SendSecurityMail $job) use (&$token): bool {
            if ($job->kind !== 'verification') {
                return false;
            }
            parse_str((string) parse_url($job->data['action_url'], PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return $token;
    }
}
