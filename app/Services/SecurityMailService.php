<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendSecurityMail;
use App\Mail\SecurityNotification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

class SecurityMailService
{
    private const ACTION_PATHS = [
        'invitation' => '/invitations/accept',
        'verification' => '/verify-email',
        'password_reset' => '/reset-password',
    ];

    /** Encola únicamente datos de presentación; los enlaces sensibles viajan cifrados. */
    public function send(string $kind, User|string $recipient, #[SensitiveParameter] array $data = []): void
    {
        SecurityNotification::subjectFor($kind);
        $email = $recipient instanceof User ? $recipient->email : Str::lower(trim($recipient));
        $payload = array_intersect_key($data, array_flip(['name', 'organization', 'role', 'expires_minutes']));
        $payload['name'] ??= $recipient instanceof User ? $recipient->name : '';
        $payload['action_url'] = $this->baseUrl().'/login';

        if (isset(self::ACTION_PATHS[$kind])) {
            $this->ensurePrivateTransport();
            $token = $data['token'] ?? '';
            if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
                throw new InvalidArgumentException('El enlace de correo no contiene un token válido.');
            }
            $payload['action_url'] = $this->baseUrl().self::ACTION_PATHS[$kind].'?token='.rawurlencode($token);
        }

        SendSecurityMail::dispatch($kind, $email, $payload)->afterCommit();
    }

    /** Solo el job cifrado entrega el mensaje; no se registra su cuerpo ni su enlace. */
    public function deliver(string $kind, string $recipient, #[SensitiveParameter] array $data): void
    {
        if (isset(self::ACTION_PATHS[$kind])) {
            $this->ensurePrivateTransport();
        }

        Mail::to($recipient)->send(new SecurityNotification($kind, $data));
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('La URL institucional de los correos no está configurada correctamente.');
        }

        return $url;
    }

    private function ensurePrivateTransport(): void
    {
        if (Mail::getFacadeRoot() instanceof MailFake) {
            return;
        }

        if ($this->includesLogTransport((string) config('mail.default'), [])) {
            throw new RuntimeException('Los correos de seguridad requieren un transporte que no registre su contenido.');
        }
    }

    private function includesLogTransport(string $name, array $visited): bool
    {
        if (in_array($name, $visited, true)) {
            return true;
        }
        $visited[] = $name;
        $mailer = config('mail.mailers.'.$name, []);
        if (($mailer['transport'] ?? '') === 'log') {
            return true;
        }
        foreach ($mailer['mailers'] ?? [] as $nested) {
            if ($this->includesLogTransport((string) $nested, $visited)) {
                return true;
            }
        }

        return false;
    }
}
