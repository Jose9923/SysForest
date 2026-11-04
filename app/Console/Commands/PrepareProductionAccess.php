<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductionAccessService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Entrada administrativa explícita; jamás acepta contraseñas en argumentos o variables de entorno. */
class PrepareProductionAccess extends Command
{
    protected $signature = 'sysforest:prepare-production-access
        {--admin-email= : Correo real confirmado por el operador para el administrador}
        {--admin-name= : Nombre del administrador nuevo}
        {--password-file= : Archivo privado 0400 o 0600 con la contraseña del administrador nuevo}
        {--revoke-all-sessions : Invalida todas las sesiones y enlaces de recuperación de la base restaurada}
        {--apply : Retira claves de desarrollo y prepara el administrador de forma atómica}';

    protected $description = 'Inspecciona el acceso restaurado; --apply retira únicamente la contraseña de desarrollo y crea un administrador nuevo.';

    public function handle(ProductionAccessService $access): int
    {
        $password = null;
        try {
            $email = trim((string) $this->option('admin-email'));
            $preview = $access->inspect($email === '' ? null : $email);
            if (!$this->option('apply')) {
                $this->line(json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $this->comment('Vista previa sin cambios. Para aplicar, confirma el correo real y utiliza --apply.');

                return self::SUCCESS;
            }
            if ($email === '' || $preview['administrator'] === 'blocked') {
                $this->error('Para aplicar, indica un correo real nuevo o el de un superadministrador activo y verificado con contraseña propia. No se modificó ninguna cuenta.');

                return self::INVALID;
            }
            if ($preview['administrator'] === 'new') {
                $file = (string) $this->option('password-file');
                if ($file !== '') {
                    $password = $this->readPasswordFile($file);
                } else {
                    if (!$this->input->isInteractive() || !defined('STDIN') || !stream_isatty(STDIN)) {
                        throw ValidationException::withMessages(['access' => 'Usa --password-file con un archivo privado o una terminal interactiva con entrada oculta.']);
                    }
                    $password = $this->secret('Contraseña del administrador nuevo', fallback: false);
                    $confirmation = $this->secret('Confirma la contraseña', fallback: false);
                    if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
                        throw ValidationException::withMessages(['access' => 'Las contraseñas no coinciden.']);
                    }
                    unset($confirmation);
                }
            }
            $result = $access->prepare(
                $email,
                (string) $this->option('admin-name'),
                $password,
                (bool) $this->option('revoke-all-sessions')
            );
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->comment('No se enviaron correos. Las cuentas cuya clave se retiró requieren recuperación de acceso por un canal configurado o gestión administrativa.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::INVALID;
        } catch (Throwable) {
            $this->error('No fue posible preparar el acceso. Revisa el entorno production, la restauración y las autorizaciones existentes.');

            return self::FAILURE;
        } finally {
            unset($password);
        }
    }

    private function readPasswordFile(string $path): string
    {
        $real = realpath($path);
        $info = @lstat($path);
        if (!str_starts_with($path, '/') || $real === false || $info === false
            || ($info['mode'] & 0170000) !== 0100000 || ($info['mode'] & 07177) !== 0
            || ($info['mode'] & 0400) === 0) {
            throw ValidationException::withMessages(['access' => 'El archivo de contraseña debe ser regular, legible, de ruta absoluta y permisos 0400 o 0600, sin enlaces simbólicos.']);
        }
        foreach ([public_path(), storage_path('app/public')] as $directory) {
            foreach (array_filter([$directory, realpath($directory)]) as $forbidden) {
                if ($real === $forbidden || str_starts_with($real, rtrim($forbidden, '/').'/')) {
                    throw ValidationException::withMessages(['access' => 'El archivo de contraseña debe permanecer fuera de las carpetas públicas.']);
                }
            }
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['access' => 'No se pudo leer el archivo privado de contraseña.']);
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['ino'] !== $info['ino'] || $opened['dev'] !== $info['dev']
                || ($opened['mode'] & 07177) !== 0 || $opened['size'] > 2048) {
                throw ValidationException::withMessages(['access' => 'El archivo privado cambió o excede el tamaño admitido.']);
            }
            $password = stream_get_contents($handle, 2049);
            if (!is_string($password) || strlen($password) > 2048) {
                throw ValidationException::withMessages(['access' => 'No se pudo leer una contraseña válida del archivo privado.']);
            }
            $password = preg_replace('/\r?\n\z/', '', $password);
            if ($password === '' || str_contains($password, "\n") || str_contains($password, "\r") || str_contains($password, "\0")) {
                throw ValidationException::withMessages(['access' => 'El archivo debe contener una única contraseña, sin líneas adicionales ni caracteres nulos.']);
            }

            return $password;
        } finally {
            fclose($handle);
        }
    }
}
