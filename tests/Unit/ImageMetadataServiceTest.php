<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImageMetadataService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImageMetadataServiceTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['evidence.max_size_mb' => 10, 'evidence.max_pixels' => 20000000,
            'evidence.allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    #[DataProvider('formats')]
    public function test_inspects_real_bytes_and_preserves_original_content(string $extension, string $mime): void
    {
        $file = $this->file($this->image($extension), 'fotografía.'.$extension, 'application/octet-stream');
        $before = hash_file('sha256', $file->getPathname());
        $result = (new ImageMetadataService())->inspect($file);

        $this->assertSame($mime, $result['mime_type']);
        $this->assertSame($extension === 'jpeg' ? 'jpg' : $extension, $result['extension']);
        $this->assertSame(12, $result['width']);
        $this->assertSame(8, $result['height']);
        $this->assertSame(filesize($file->getPathname()), $result['size_bytes']);
        $this->assertSame($before, $result['checksum_sha256']);
        $this->assertSame($before, hash_file('sha256', $file->getPathname()));
        $this->assertSame('fotografía.'.$extension, $result['original_name']);
        $this->assertFalse($result['metadata']['exif_extracted']);
        $this->assertTrue($result['metadata']['original_preserved']);
        $this->assertArrayNotHasKey('captured_at', $result);
    }

    public static function formats(): array
    {
        return [['jpg', 'image/jpeg'], ['jpeg', 'image/jpeg'], ['png', 'image/png'], ['webp', 'image/webp']];
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_inconsistent_or_executable_extensions(string $name): void
    {
        $this->expectException(ValidationException::class);
        (new ImageMetadataService())->inspect($this->file($this->image('png'), $name));
    }

    public static function invalidNames(): array
    {
        return [['falso.jpg'], ['foto.php.png'], ['foto.PHP8.PNG'], ['foto.phar.png'], ['foto.svg.png'],
            ['foto.html.png'], ['foto.exe.png'], ['foto.js.png'], ['foto'], ['foto.png.php'], ['foto.heic']];
    }

    #[DataProvider('invalidContents')]
    public function test_rejects_non_images_even_with_image_name_and_client_mime(string $bytes): void
    {
        try {
            (new ImageMetadataService())->inspect($this->file($bytes, 'foto.jpg', 'image/jpeg'), 'files.2.file');
            $this->fail('Se aceptó contenido ajeno a una fotografía.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('files.2.file', $error->errors());
        }
    }

    public static function invalidContents(): array
    {
        return [[''], ['texto'], ['<?php echo "ejecutable";'], ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
            ['<html><script>alert(1)</script></html>']];
    }

    #[DataProvider('formats')]
    public function test_rejects_truncated_images(string $extension, string $mime): void
    {
        $bytes = $this->image($extension);
        $this->expectException(ValidationException::class);
        (new ImageMetadataService())->inspect($this->file(substr($bytes, 0, -10), 'foto.'.$extension));
    }

    #[DataProvider('formats')]
    public function test_rejects_payload_appended_after_container(string $extension, string $mime): void
    {
        $this->expectException(ValidationException::class);
        (new ImageMetadataService())->inspect($this->file($this->image($extension).'<?php echo 1;', 'foto.'.$extension));
    }

    public function test_rejects_corrupt_pixels_even_when_header_and_ending_remain_valid(): void
    {
        $bytes = $this->image('png');
        $offset = strpos($bytes, 'IDAT') + 4;
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 255);
        $this->expectException(ValidationException::class);
        (new ImageMetadataService())->inspect($this->file($bytes, 'foto.png'));
    }

    public function test_rejects_size_before_reading_the_image(): void
    {
        config(['evidence.max_size_mb' => 1]);
        try {
            (new ImageMetadataService())->inspect($this->file(str_repeat('x', 1048577), 'foto.jpg'));
            $this->fail('Se aceptó un archivo por encima del límite.');
        } catch (ValidationException $error) {
            $this->assertSame('La imagen supera el tamaño máximo permitido.', $error->errors()['file'][0]);
        }
    }

    public function test_applies_pixel_limit_before_full_decode(): void
    {
        config(['evidence.max_pixels' => 95]);
        try {
            (new ImageMetadataService())->inspect($this->file($this->image('png'), 'foto.png'));
            $this->fail('Se aceptó una imagen por encima del límite de píxeles.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('píxeles', $error->errors()['file'][0]);
        }
    }

    public function test_pixel_limit_includes_exact_boundary(): void
    {
        config(['evidence.max_pixels' => 96]);
        $this->assertSame(12, (new ImageMetadataService())->inspect($this->file($this->image('png'), 'foto.png'))['width']);
    }

    public function test_rejects_header_with_decompression_bomb_dimensions_without_allocating_pixels(): void
    {
        $bytes = $this->image('png');
        $bytes = substr_replace($bytes, pack('NN', 100000, 100000), 16, 8);
        $bytes = substr_replace($bytes, pack('N', crc32(substr($bytes, 12, 17))), 29, 4);
        try {
            (new ImageMetadataService())->inspect($this->file($bytes, 'foto.png'));
            $this->fail('Se intentó aceptar una imagen con dimensiones desproporcionadas.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('píxeles', $error->errors()['file'][0]);
        }
    }

    public function test_memory_headroom_is_checked_before_allocating_the_decoder(): void
    {
        $file = $this->file($this->image('png'), 'foto.png');
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 16777216));
        try {
            (new ImageMetadataService())->inspect($file);
            $this->fail('No se comprobó la reserva de memoria.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('memoria', $error->errors()['file'][0]);
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    public function test_mime_configuration_only_restricts_the_supported_allowlist(): void
    {
        config(['evidence.allowed_mimes' => ['image/jpeg', 'image/svg+xml']]);
        $this->expectException(ValidationException::class);
        (new ImageMetadataService())->inspect($this->file($this->image('png'), 'foto.png'));
    }

    public function test_sanitizes_path_and_control_characters_but_keeps_name_as_metadata(): void
    {
        $name = '..\\..\\retrato'.chr(10).'ñ.png';
        $result = (new ImageMetadataService())->inspect($this->file($this->image('png'), $name));
        $this->assertSame('retratoñ.png', $result['original_name']);
    }

    private function file(string $bytes, string $name, string $clientMime = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sysforest-image-test-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $clientMime, UPLOAD_ERR_OK, true);
    }

    private function image(string $extension): string
    {
        $image = imagecreatetruecolor(12, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 130, 80));
        ob_start();
        match ($extension) {
            'jpg', 'jpeg' => imagejpeg($image),
            'png' => imagepng($image),
            'webp' => imagewebp($image),
        };
        $bytes = ob_get_clean();
        unset($image);

        return $bytes;
    }
}
