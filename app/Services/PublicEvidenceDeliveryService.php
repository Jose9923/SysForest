<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evidence;
use App\Repositories\PublicTreeRepository;
use GdImage;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Nunca entrega originales: decodifica píxeles, aplica orientación y codifica un WebP sin EXIF/XMP/IPTC. */
class PublicEvidenceDeliveryService
{
    public function __construct(private PublicTreeRepository $trees, private EvidenceStorageService $storage)
    {
    }

    /** La elegibilidad se consulta antes de la caché y, por tanto, antes del 304 del Controller. */
    public function image(string $publicId): array
    {
        $evidence = $this->trees->findEvidence($publicId);
        $quality = (int) config('public-portal.image_quality');
        $key = 'public-image-v1:'.hash('sha256', $evidence->id.'|'.$evidence->checksum_sha256.'|'.$quality);
        $bytes = Cache::store('file')->remember(
            $key,
            (int) config('public-portal.image_cache_seconds'),
            fn () => $this->encode($evidence, $quality)
        );

        return ['bytes' => $bytes, 'etag' => hash('sha256', $bytes)];
    }

    private function encode(Evidence $evidence, int $quality): string
    {
        $stream = $this->storage->read($evidence);
        try {
            $limit = (int) config('evidence.max_size_mb', 10) * 1024 * 1024;
            $bytes = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($bytes) || strlen($bytes) > $limit) {
            throw new RuntimeException('La imagen supera el tamaño autorizado.');
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || !in_array($size['mime'], config('evidence.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']), true)
            || $size[0] * $size[1] > (int) config('evidence.max_pixels', 20000000)) {
            throw new RuntimeException('No fue posible interpretar la imagen autorizada.');
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof GdImage) {
            throw new RuntimeException('No fue posible decodificar la imagen autorizada.');
        }
        $orientation = $size['mime'] === 'image/jpeg' ? $this->orientation($bytes) : 1;
        unset($bytes);
        $image = $this->orient($image, $orientation);
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        try {
            $success = imagewebp($image, null, max(1, min(100, $quality)));
            $result = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (!$success || !is_string($result) || $result === '') {
            throw new RuntimeException('No fue posible preparar la imagen pública.');
        }

        return $result;
    }

    /** Solo interpreta orientación; los demás metadatos nunca salen del proceso ni se guardan en el derivado. */
    private function orientation(string $bytes): int
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No fue posible preparar la orientación de la imagen.');
        }
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $data = @exif_read_data($stream, 'IFD0', true, false);

            return (int) ($data['IFD0']['Orientation'] ?? 1);
        } finally {
            fclose($stream);
        }
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 5, 7], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        imagedestroy($image);
        if (!$rotated instanceof GdImage) {
            throw new RuntimeException('No fue posible orientar la imagen.');
        }

        return $rotated;
    }
}
