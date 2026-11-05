<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Auth\SecurityMailService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;
use SensitiveParameter;

class SendSecurityMail implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public string $kind, public string $recipient, #[SensitiveParameter] public array $data)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(SecurityMailService $mail): void
    {
        try {
            $mail->deliver($this->kind, $this->recipient, $this->data);
        } catch (Throwable) {
            // Una excepción SMTP no puede copiar el enlace o el cuerpo al registro de jobs fallidos.
            throw new RuntimeException('No fue posible entregar el correo de seguridad.');
        }
    }
}
