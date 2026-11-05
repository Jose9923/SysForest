<?php

declare(strict_types=1);

namespace App\Mail\Auth;

use Illuminate\Mail\Mailable;
use InvalidArgumentException;
use SensitiveParameter;

class SecurityNotification extends Mailable
{
    public function __construct(public string $kind, #[SensitiveParameter] public array $data)
    {
    }

    public static function subjectFor(string $kind): string
    {
        return match ($kind) {
            'invitation' => 'Invitación a SYSFOREST',
            'welcome' => 'Bienvenido a SYSFOREST',
            'verification' => 'Verifica tu correo en SYSFOREST',
            'password_reset' => 'Recupera tu acceso a SYSFOREST',
            'account_activated' => 'Tu cuenta SYSFOREST está activa',
            'account_deactivated' => 'Tu cuenta SYSFOREST fue desactivada',
            'membership_activated' => 'Tu acceso institucional está activo',
            'membership_deactivated' => 'Tu acceso institucional fue desactivado',
            default => throw new InvalidArgumentException('Tipo de correo de seguridad no permitido.'),
        };
    }

    public function build(): static
    {
        return $this->subject(self::subjectFor($this->kind))
            ->view('mail.security', ['title' => self::subjectFor($this->kind)]);
    }
}
