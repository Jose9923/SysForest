<?php

declare(strict_types=1);

namespace App\Services;

use ErrorException;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Inspecciona el contenido local recibido; nunca confía en metadata del navegador. */
class ImageMetadataService
{
    private const FORMATS = [
        'image/jpeg' => ['jpg', IMAGETYPE_JPEG, ['jpg', 'jpeg']],
        'image/png' => ['png', IMAGETYPE_PNG, ['png']],
        'image/webp' => ['webp', IMAGETYPE_WEBP, ['webp']],
    ];

    /** La carga no extrae fecha, GPS ni cámara; EXIF se utiliza solo al orientar el derivado público. */
    public function inspect(UploadedFile $file, string $errorKey = 'file'): array
    {
        if (! $file->isValid()) {
            $this->invalid($errorKey, 'El archivo no se recibió correctamente.');
        }
        $size = $file->getSize();
        if (! is_int($size) || $size <= 0) {
            $this->invalid($errorKey);
        }
        if ($size > (int) config('evidence.max_size_mb') * 1048576) {
            $this->invalid($errorKey, 'La imagen supera el tamaño máximo permitido.');
        }
        $source = $file->getRealPath();
        if ($source === false) {
            $this->invalid($errorKey);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
        if (! is_string($mime) || ! isset(self::FORMATS[$mime])
            || ! in_array($mime, config('evidence.allowed_mimes', []), true)) {
            $this->invalid($errorKey, 'El archivo no es una imagen JPEG, PNG o WebP válida.');
        }

        $name = $this->safeOriginalName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, self::FORMATS[$mime][2], true)
            || preg_match('/(?:^|\.)(?:php\d*|phtml|phar|html?|svg|js|exe|com|bat|cmd|sh)(?:\.|$)/i', $name)) {
            $this->invalid($errorKey, 'La extensión del archivo no corresponde a una fotografía permitida.');
        }

        $header = $this->readImageHeader($source, $errorKey);
        $width = $header[0] ?? 0;
        $height = $header[1] ?? 0;
        if ($width <= 0 || $height <= 0 || ($header[2] ?? null) !== self::FORMATS[$mime][1]) {
            $this->invalid($errorKey);
        }
        if ($width > intdiv(max(0, (int) config('evidence.max_pixels')), $height)) {
            $this->invalid($errorKey, 'La imagen supera el límite de píxeles permitido para procesarla con seguridad.');
        }
        $this->assertMemoryBudget($width * $height, $size, $errorKey);

        $bytes = file_get_contents($source);
        if ($bytes === false || strlen($bytes) !== $size || ! $this->hasCompleteContainer($bytes, $mime)) {
            $this->invalid($errorKey);
        }
        $image = null;
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException('No se pudo decodificar la imagen.', 0, $severity);
        });
        try {
            $image = imagecreatefromstring($bytes);
            if ($image === false || imagesx($image) !== $width || imagesy($image) !== $height) {
                $this->invalid($errorKey);
            }
        } catch (Throwable) {
            $this->invalid($errorKey);
        } finally {
            restore_error_handler();
            unset($image, $bytes);
        }

        $checksum = hash_file('sha256', $source);
        if (! is_string($checksum)) {
            $this->invalid($errorKey);
        }

        return [
            'mime_type' => $mime,
            'extension' => self::FORMATS[$mime][0],
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'checksum_sha256' => $checksum,
            'original_name' => $name,
            'metadata' => [
                'validation' => 'finfo_header_gd_v1',
                'exif_extracted' => false,
                'original_preserved' => true,
            ],
        ];
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if (! mb_check_encoding($name, 'UTF-8')) {
            return '';
        }
        $name = preg_replace('/[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);

        return mb_substr($name ?? '', 0, 255);
    }

    private function readImageHeader(string $source, string $errorKey): array
    {
        set_error_handler(static function (int $severity): never {
            throw new ErrorException('La cabecera de imagen no es válida.', 0, $severity);
        });
        try {
            $header = getimagesize($source);
            if ($header === false) {
                $this->invalid($errorKey);
            }

            return $header;
        } catch (Throwable) {
            $this->invalid($errorKey);
        } finally {
            restore_error_handler();
        }
    }

    /** Cabeceras y decodificación se complementan; no prueba ausencia universal de políglotas. */
    private function hasCompleteContainer(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8") && str_ends_with($bytes, "\xFF\xD9"),
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n")
                && str_ends_with($bytes, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82"),
            'image/webp' => strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF'
                && substr($bytes, 8, 4) === 'WEBP' && unpack('Vsize', substr($bytes, 4, 4))['size'] + 8 === strlen($bytes),
            default => false,
        };
    }

    /** Reserva conservadora para GD, buffers y aplicación; el límite de píxeles sigue siendo obligatorio. */
    private function assertMemoryBudget(int $pixels, int $size, string $errorKey): void
    {
        $configured = trim((string) ini_get('memory_limit'));
        if ($configured === '-1') {
            return;
        }
        $unit = strtolower(substr($configured, -1));
        $limit = (int) $configured * match ($unit) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
        if ($limit > 0 && memory_get_usage(true) + $pixels * 10 + $size * 2 + 33554432 > $limit) {
            $this->invalid($errorKey, 'La resolución requiere más memoria de la disponible. Reduce las dimensiones de la imagen.');
        }
    }

    private function invalid(string $key, string $message = 'El archivo no es una imagen válida.'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
