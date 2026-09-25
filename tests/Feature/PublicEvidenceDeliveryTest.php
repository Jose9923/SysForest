<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Services\EvidenceService;
use App\Services\EvidenceStorageService;
use App\Services\MaintenanceService;
use App\Services\TreeLifecycleService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PublicPortalTestCase;

class PublicEvidenceDeliveryTest extends PublicPortalTestCase
{
    private string $imageCachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->imageCachePath = storage_path('framework/testing/public-image-'.Str::uuid());
        config(['cache.stores.file.path' => $this->imageCachePath, 'public-portal.image_quality' => 100]);
        Cache::purge('file');
        $this->publishTree();
    }

    protected function tearDown(): void
    {
        Cache::purge('file');
        File::deleteDirectory($this->imageCachePath);
        parent::tearDown();
    }

    public function test_anonymous_delivery_reencodes_jpeg_without_exif_xmp_iptc_or_comments_and_preserves_original(): void
    {
        $original = $this->jpeg(1, true);
        $evidence = $this->upload($original, 'original.jpg');
        $response = $this->get($this->url($evidence))->assertOk()->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename="arbol.webp"');
        $bytes = $response->getContent();
        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertSame('WEBP', substr($bytes, 8, 4));
        $this->assertSame('image/webp', getimagesizefromstring($bytes)['mime']);
        foreach (['EXIF-SENSITIVE-GPS', 'XMP-SENSITIVE-GPS', 'IPTC-SENSITIVE-STAFF', 'COMMENT-SENSITIVE',
            'Exif', 'http://ns.adobe.com/xap/1.0/', 'Photoshop 3.0'] as $marker) {
            $this->assertStringNotContainsString($marker, $bytes);
        }
        $this->assertEmpty(array_intersect(['EXIF', 'XMP ', 'ICCP'], $this->webpChunks($bytes)));
        $this->assertNotSame($original, $bytes);
        $this->assertSame($original, Storage::disk('evidences')->get($evidence->path));
        $this->assertSame(hash('sha256', $original), $evidence->fresh()->checksum_sha256);
        $this->assertStringNotContainsString($evidence->id, (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_png_text_metadata_and_transparency_are_not_leaked_or_destroyed(): void
    {
        $image = imagecreatetruecolor(24, 16);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 8, 5, 15, 10, imagecolorallocatealpha($image, 240, 10, 20, 0));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $text = "Description\0PNG-SENSITIVE-GPS-and-name";
        $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
        $original = substr($png, 0, 33).$chunk.substr($png, 33);
        $evidence = $this->upload($original, 'original.png');
        $bytes = $this->get($this->url($evidence))->assertOk()->getContent();
        $this->assertStringNotContainsString('PNG-SENSITIVE-GPS-and-name', $bytes);
        $decoded = imagecreatefromstring($bytes);
        $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        imagedestroy($decoded);
        $this->assertSame($original, Storage::disk('evidences')->get($evidence->path));
    }

    #[DataProvider('orientations')]
    public function test_eight_exif_orientations_produce_correct_visual_quadrants(int $orientation, array $corners): void
    {
        $this->assertTrue(function_exists('exif_read_data'), 'El contenedor debe incorporar EXIF para interpretar orientación.');
        $evidence = $this->upload($this->jpeg($orientation), 'orientation.jpg');
        $bytes = $this->get($this->url($evidence))->assertOk()->getContent();
        $image = imagecreatefromstring($bytes);
        $width = $orientation >= 5 ? 24 : 40;
        $height = $orientation >= 5 ? 40 : 24;
        $this->assertSame($width, imagesx($image));
        $this->assertSame($height, imagesy($image));
        $points = [[3, 3], [$width - 4, 3], [3, $height - 4], [$width - 4, $height - 4]];
        foreach ($points as $index => [$x, $y]) {
            $this->assertSame($corners[$index], $this->colorName($image, $x, $y), 'Orientación '.$orientation.', esquina '.$index);
        }
        $this->assertEmpty(array_intersect(['EXIF', 'XMP ', 'ICCP'], $this->webpChunks($bytes)));
        imagedestroy($image);
    }

    public static function orientations(): array
    {
        return [
            [1, ['red', 'green', 'blue', 'yellow']],
            [2, ['green', 'red', 'yellow', 'blue']],
            [3, ['yellow', 'blue', 'green', 'red']],
            [4, ['blue', 'yellow', 'red', 'green']],
            [5, ['red', 'blue', 'green', 'yellow']],
            [6, ['blue', 'red', 'yellow', 'green']],
            [7, ['yellow', 'green', 'blue', 'red']],
            [8, ['green', 'yellow', 'red', 'blue']],
        ];
    }

    public function test_webp_original_is_decoded_and_reencoded(): void
    {
        $image = imagecreatetruecolor(20, 12);
        imagefill($image, 0, 0, imagecolorallocate($image, 50, 180, 40));
        ob_start();
        imagewebp($image, null, 75);
        $original = ob_get_clean();
        imagedestroy($image);
        $evidence = $this->upload($original, 'original.webp');
        $response = $this->get($this->url($evidence))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertSame('image/webp', getimagesizefromstring($response->getContent())['mime']);
        $this->assertSame($original, Storage::disk('evidences')->get($evidence->path));
    }

    public function test_conditional_request_uses_derivative_etag_and_never_returns_original(): void
    {
        $evidence = $this->upload($this->jpeg(), 'original.jpg');
        $first = $this->get($this->url($evidence))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertSame('"'.hash('sha256', $first->getContent()).'"', $etag);
        $this->assertNotSame('"'.$evidence->checksum_sha256.'"', $etag);
        $response = $this->withHeader('If-None-Match', $etag)->get($this->url($evidence))->assertStatus(304);
        $this->assertSame('', $response->getContent());
    }

    public function test_warm_private_cache_avoids_rereading_the_original(): void
    {
        $evidence = $this->upload($this->jpeg(), 'original.jpg');
        $first = $this->get($this->url($evidence))->assertOk()->getContent();
        $this->mock(EvidenceStorageService::class)->shouldNotReceive('read');
        $second = $this->get($this->url($evidence))->assertOk()->getContent();
        $this->assertSame($first, $second);
        $this->assertTrue(is_dir($this->imageCachePath));
    }

    #[DataProvider('publicationRevocations')]
    public function test_every_tree_publication_layer_is_revalidated_before_warm_cache_and_304(string $layer): void
    {
        $evidence = $this->upload($this->jpeg(), 'original.jpg');
        $etag = $this->get($this->url($evidence))->assertOk()->headers->get('ETag');
        match ($layer) {
            'organization' => DB::table('organizations')->where('id', $this->organization->id)->update(['is_public' => false]),
            'organization_inactive' => DB::table('organizations')->where('id', $this->organization->id)->update(['active' => false]),
            'inventory' => DB::table('inventories')->where('id', $this->inventory->id)->update(['is_public' => false]),
            'inventory_inactive' => DB::table('inventories')->where('id', $this->inventory->id)->update(['active' => false]),
            'inventory_draft' => DB::table('inventories')->where('id', $this->inventory->id)->update(['status' => 'draft']),
            'tree' => DB::table('trees')->where('id', $this->tree->id)->update(['is_public' => false]),
            'evidence' => DB::table('evidences')->where('id', $evidence->id)->update(['is_public' => false]),
            'evidence_voided' => app(EvidenceService::class)->void($this->admin, $evidence->id, ['lock_version' => $evidence->lock_version]),
            'tree_voided' => app(TreeLifecycleService::class)->void($this->admin, $this->tree->id, [
                'lock_version' => $this->tree->fresh()->lock_version,
                'deletion_reason_id' => DB::table('deletion_reasons')->where('active', true)->value('id'),
            ]),
            'municipality' => DB::table('municipalities')->where('id', $this->organization->municipality_id)->update(['active' => false]),
        };
        $this->withHeader('If-None-Match', $etag)->get($this->url($evidence))->assertNotFound();
    }

    public static function publicationRevocations(): array
    {
        return array_map(fn ($layer) => [$layer], ['organization', 'organization_inactive', 'inventory',
            'inventory_inactive', 'inventory_draft', 'tree', 'evidence', 'evidence_voided', 'tree_voided', 'municipality']);
    }

    public function test_inspection_owner_revocation_hides_photo_even_when_tree_remains_public(): void
    {
        $inspection = $this->publishInspection($this->capture());
        $evidence = $this->publishEvidence($this->attachEvidence([], null, $inspection->id));
        $etag = $this->get($this->url($evidence))->assertOk()->headers->get('ETag');
        DB::table('tree_inspections')->where('id', $inspection->id)->update(['is_public' => false]);
        $this->withHeader('If-None-Match', $etag)->get($this->url($evidence))->assertNotFound();
        $this->assertTrue($this->tree->fresh()->is_public);
    }

    public function test_private_inspection_campaign_hides_photo_despite_another_public_campaign(): void
    {
        $campaign = app(\App\Services\InventoryService::class)->create(
            $this->admin,
            ['code' => 'PRIVATE-VISIT', 'name' => 'Visita privada', 'status' => 'active', 'active' => true]
        );
        app(\App\Repositories\InventoryRepository::class)->attach($this->organization->id, $campaign->id, $this->tree->id, (int) $this->admin->id);
        $participation = DB::table('inventory_trees')->where('inventory_id', $campaign->id)->where('tree_id', $this->tree->id)->value('id');
        $inspection = $this->publishInspection($this->capture(['inventory_tree_id' => $participation]));
        $evidence = $this->publishEvidence($this->attachEvidence([], null, $inspection->id));
        $this->get($this->url($evidence))->assertNotFound();
        $this->publishInventory($campaign);
        $etag = $this->get($this->url($evidence))->assertOk()->headers->get('ETag');
        DB::table('inventories')->where('id', $campaign->id)->update(['is_public' => false]);
        $this->withHeader('If-None-Match', $etag)->get($this->url($evidence))->assertNotFound();
    }

    public function test_annulling_executed_maintenance_hides_previously_cached_photo(): void
    {
        $maintenance = $this->executedOrder();
        $evidence = $this->publishEvidence(app(EvidenceService::class)->upload(
            $this->admin,
            null,
            null,
            $this->evidenceInput(),
            $maintenance->id
        )->sole());
        $etag = $this->get($this->url($evidence))->assertOk()->headers->get('ETag');
        app(MaintenanceService::class)->void($this->admin, $maintenance->id, [
            'lock_version' => $maintenance->lock_version, 'deletion_notes' => 'Registro de prueba anulado.',
        ]);
        $this->withHeader('If-None-Match', $etag)->get($this->url($evidence))->assertNotFound();
    }

    public function test_pending_maintenance_photos_are_never_public_even_with_a_flag(): void
    {
        $maintenance = $this->order();
        $evidence = $this->publishEvidence(app(EvidenceService::class)->upload(
            $this->admin,
            null,
            null,
            $this->evidenceInput(),
            $maintenance->id
        )->sole());
        $this->get($this->url($evidence))->assertNotFound();
    }

    public function test_private_uuid_and_storage_paths_cannot_bypass_public_identity(): void
    {
        $evidence = $this->upload($this->jpeg(), 'original.jpg');
        $this->get('/portal/media/'.$evidence->id)->assertNotFound();
        $this->get('/storage/evidences/'.$evidence->path)->assertNotFound();
        $this->get(route('evidences.view', $evidence->id))->assertRedirect(route('login'));
        $this->get('/portal/media/'.(string) Str::ulid())->assertNotFound();
    }

    public function test_corrupt_original_returns_safe_error_without_paths_or_partial_bytes(): void
    {
        $evidence = $this->upload($this->jpeg(), 'original.jpg');
        Storage::disk('evidences')->put($evidence->path, 'CORRUPT-PRIVATE-FILE');
        $response = $this->getJson($this->url($evidence))->assertStatus(503);
        foreach ([$evidence->path, 'CORRUPT-PRIVATE-FILE', 'trace', '/var/www', 'checksum_sha256'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    private function upload(string $bytes, string $name): Evidence
    {
        $file = UploadedFile::fake()->createWithContent($name, $bytes);

        return $this->publishEvidence($this->attachEvidence(['file' => $file]));
    }

    private function url(Evidence $evidence): string
    {
        return route('public.evidences.file', ['evidence' => $evidence->public_id]);
    }

    /** TIFF mínimo y válido: orientación más una descripción privada sintética. */
    private function jpeg(int $orientation = 1, bool $extraMetadata = false): string
    {
        $image = imagecreatetruecolor(40, 24);
        foreach ([[0, 0, 19, 11, 250, 0, 0], [20, 0, 39, 11, 0, 250, 0],
            [0, 12, 19, 23, 0, 0, 250], [20, 12, 39, 23, 250, 250, 0]] as [$x1, $y1, $x2, $y2, $red, $green, $blue]) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, imagecolorallocate($image, $red, $green, $blue));
        }
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $description = "EXIF-SENSITIVE-GPS 4.123,-73.456\0";
        $tiff = 'II'.pack('vVv', 42, 8, 2)
            .pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0"
            .pack('vvVV', 0x010E, 2, strlen($description), 38).pack('V', 0).$description;
        $segments = $this->jpegSegment(0xE1, "Exif\0\0".$tiff);
        if ($extraMetadata) {
            $segments .= $this->jpegSegment(0xE1, "http://ns.adobe.com/xap/1.0/\0<xmp>XMP-SENSITIVE-GPS</xmp>");
            $iptc = "\x1C\x02\x78".pack('n', strlen('IPTC-SENSITIVE-STAFF')).'IPTC-SENSITIVE-STAFF';
            $segments .= $this->jpegSegment(0xED, "Photoshop 3.0\0".'8BIM'.pack('n', 0x0404)."\0\0".pack('N', strlen($iptc)).$iptc.(strlen($iptc) % 2 ? "\0" : ''));
            $segments .= $this->jpegSegment(0xFE, 'COMMENT-SENSITIVE');
        }

        return substr($jpeg, 0, 2).$segments.substr($jpeg, 2);
    }

    private function jpegSegment(int $marker, string $content): string
    {
        return "\xFF".chr($marker).pack('n', strlen($content) + 2).$content;
    }

    private function colorName(\GdImage $image, int $x, int $y): string
    {
        $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        $palette = ['red' => [250, 0, 0], 'green' => [0, 250, 0], 'blue' => [0, 0, 250], 'yellow' => [250, 250, 0]];
        $distances = [];
        foreach ($palette as $name => [$red, $green, $blue]) {
            $distances[$name] = ($red - $pixel['red']) ** 2 + ($green - $pixel['green']) ** 2 + ($blue - $pixel['blue']) ** 2;
        }
        asort($distances);

        return array_key_first($distances);
    }

    private function webpChunks(string $bytes): array
    {
        $chunks = [];
        for ($offset = 12; $offset + 8 <= strlen($bytes);) {
            $chunks[] = substr($bytes, $offset, 4);
            $length = unpack('Vlength', substr($bytes, $offset + 4, 4))['length'];
            $offset += 8 + $length + ($length % 2);
        }

        return $chunks;
    }
}
