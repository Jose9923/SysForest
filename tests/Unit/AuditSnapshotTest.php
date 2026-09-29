<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auditing\AuditSnapshot;
use App\Models\Organization;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AuditSnapshotTest extends TestCase
{
    public function test_identity_snapshot_excludes_credentials_contact_and_arbitrary_new_attributes(): void
    {
        $snapshot = new AuditSnapshot();
        $values = $snapshot->sanitize('user', [
            'name' => 'Funcionaria', 'active' => true, 'email' => 'privado@example.test', 'password' => 'hash-secreto',
            'remember_token' => 'secreto', 'access_token' => 'jwt', 'refresh_token' => 'opaco', 'new_secret' => 'no incluir',
        ]);
        $this->assertSame(['name' => 'Funcionaria', 'active' => true], $values);
        $organization = $snapshot->sanitize('organization', ['name' => 'Institución', 'settings' => ['api_key' => 'secreto'],
            'logo_path' => '/private/logo.jpg', 'email' => 'privado@example.test', 'phone' => '123']);
        $this->assertSame(['name' => 'Institución'], $organization);
    }

    public function test_evidence_snapshot_excludes_paths_bytes_hash_and_exif(): void
    {
        $values = (new AuditSnapshot())->sanitize('evidence', ['caption' => 'Copa', 'notes' => 'Visita',
            'original_path' => '/private/original.jpg', 'path' => 'secreto', 'storage_disk' => 'private',
            'sha256' => 'archivo', 'original_name' => 'nombre.jpg', 'exif' => ['GPSLatitude' => 'privado'], 'bytes' => 'binario']);
        $this->assertSame(['caption' => 'Copa', 'notes' => 'Visita'], $values);
    }

    public function test_nested_inspection_projection_keeps_only_forestry_fields_and_uuid_lists(): void
    {
        $id = (string) Str::uuid();
        $values = (new AuditSnapshot())->sanitize('tree_inspection', [
            'measurements' => ['cap_cm' => '123.000000', 'password' => 'no', 'number_of_stems' => ['token' => 'no']],
            'conditions' => [$id, 'secret', ['password' => 'no']],
            'fauna' => ['category_ids' => [$id, 'secret'], 'observations' => 'Aves', 'token' => 'no'],
        ]);
        $this->assertSame(['cap_cm' => '123.000000'], $values['measurements']);
        $this->assertSame([$id], $values['conditions']);
        $this->assertSame(['category_ids' => [$id], 'observations' => 'Aves'], $values['fauna']);
    }

    public function test_model_types_are_explicit_without_dynamic_table_names(): void
    {
        $snapshot = new AuditSnapshot();
        $this->assertSame('user', $snapshot->type(new User()));
        $this->assertSame('organization', $snapshot->type(new Organization()));
        $this->assertSame('catalog', $snapshot->type(new Species()));
        $this->expectException(InvalidArgumentException::class);
        $snapshot->sanitize('users;DROP TABLE users', []);
    }
}
