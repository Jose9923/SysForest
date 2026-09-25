<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Species;
use App\Repositories\PublicTreeRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\PublicPortalTestCase;

/** Verificación por HTTP real del contrato anónimo: no basta con comprobar el servicio aislado. */
class PublicPortalHttpTest extends PublicPortalTestCase
{
    #[DataProvider('anonymousEndpoints')]
    public function test_public_endpoints_do_not_require_identity_or_issue_session_cookies(string $path, array $parameters): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->withCookie((string) config('security.access_cookie'), 'invalid-token')
            ->getJson($path.($parameters === [] ? '' : '?'.http_build_query($parameters)));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDoesNotMatchRegularExpression('/from "(users|auth_sessions|organization_memberships|role_assignments)"/i', implode(' ', $queries));
    }

    public static function anonymousEndpoints(): array
    {
        $box = ['west' => -74, 'south' => 3, 'east' => -73, 'north' => 5, 'zoom' => 15];

        return [
            ['/api/public/v1/trees', []], ['/api/public/v1/indicators', []],
            ['/api/public/v1/map/trees', $box], ['/api/public/v1/map/clusters', $box],
            ['/api/public/v1/map/heat', $box], ['/api/public/v1/map/extent', []],
            ['/api/public/v1/map/search', ['term' => 'árbol']],
            ['/api/public/v1/options/organizations', []], ['/api/public/v1/options/species', []],
        ];
    }

    public function test_detail_projects_forest_information_without_private_fields_or_values(): void
    {
        $species = Species::query()->firstOrFail();
        $this->tree->forceFill(['species_id' => $species->id, 'external_number' => 'PRIVATE-EXTERNAL-SENTINEL',
            'location_description' => 'PRIVATE-LOCATION-SENTINEL', 'coordinate_raw' => 'PRIVATE-GPS-SENTINEL',
            'gps_model' => 'PRIVATE-DEVICE-SENTINEL'])->save();
        $this->publishTree();
        $inspection = $this->publishInspection($this->capture(['general_observations' => 'PRIVATE-OBSERVATION-SENTINEL']));
        $evidence = $this->publishEvidence($this->attachEvidence(['caption' => 'PRIVATE-CAPTION-SENTINEL']), 'Fotografía del individuo');
        $this->executedOrder();
        $url = '/api/public/v1/organizations/'.$this->organization->public_code.'/trees/'.$this->tree->code;
        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.code', $this->tree->code)
            ->assertJsonPath('data.latest_inspection.date', $inspection->inspection_date->toDateString())
            ->assertJsonPath('evidences.data.0.caption', 'Fotografía del individuo')
            ->assertJsonPath('maintenances.data.0.status', 'executed');
        $this->assertPublicProjection([$response->json('data'), $response->json('inspections.data'),
            $response->json('evidences.data'), $response->json('maintenances.data')]);
        foreach (['PRIVATE-', $this->tree->id, $inspection->id, $evidence->id, $this->admin->email,
            $evidence->path, $evidence->checksum_sha256] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame('0.490197224723', $response->json('data.latest_inspection.measurements.1.value'));
    }

    public function test_newer_private_inspection_does_not_change_public_latest_or_reveal_existence(): void
    {
        $this->publishTree();
        $published = $this->publishInspection($this->capture(['inspection_date' => '2026-08-01']));
        $this->capture(['inspection_date' => '2026-09-01', 'cap_cm' => '300']);
        $url = '/api/public/v1/organizations/'.$this->organization->public_code.'/trees/'.$this->tree->code;
        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.latest_inspection.date', $published->inspection_date->toDateString())
            ->assertJsonPath('inspections.total', 1);
        $this->assertStringNotContainsString('2026-09-01', $response->getContent());
    }

    #[DataProvider('hiddenParents')]
    public function test_revocation_removes_tree_from_every_public_surface(string $parent): void
    {
        $tree = $this->publishTree($this->point(-73.65, 4.14));
        $model = match ($parent) {
            'organization' => $this->organization, 'inventory' => $this->inventory, default => $tree
        };
        $model->forceFill(['is_public' => false])->save();
        $this->getJson('/api/public/v1/trees')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/public/v1/indicators')->assertOk()->assertJsonPath('trees', 0);
        foreach (['trees', 'clusters', 'heat'] as $kind) {
            $this->getJson('/api/public/v1/map/'.$kind.'?'.http_build_query($this->bbox()))
                ->assertOk()->assertJsonCount(0, 'features')->assertJsonPath('meta.total', 0);
        }
        $this->getJson('/api/public/v1/map/search?term='.$tree->code)->assertOk()->assertJsonCount(0, 'results');
        $this->getJson('/api/public/v1/organizations/'.$this->organization->public_code.'/trees/'.$tree->code)->assertNotFound();
    }

    public static function hiddenParents(): array
    {
        return [['organization'], ['inventory'], ['tree']];
    }

    public function test_composite_code_resolves_collisions_without_using_private_uuid(): void
    {
        $first = $this->publishTree($this->point(-73.65, 4.14, ['code' => 'SAME-CODE']));
        $other = $this->anotherOrganization();
        $inventory = new Inventory();
        $inventory->forceFill(['organization_id' => $other->id, 'code' => 'OTHER-CAMPAIGN',
            'name' => 'Otra campaña', 'status' => 'active', 'active' => true,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id])->save();
        $second = $this->publishTree($this->point(-73.66, 4.15, ['code' => 'SAME-CODE'], $other), $inventory);
        foreach ([$first, $second] as $tree) {
            $tree->unsetRelation('organization');
            $code = $tree->organization->public_code;
            $this->getJson('/api/public/v1/organizations/'.$code.'/trees/SAME-CODE')
                ->assertOk()->assertJsonPath('data.organization.code', $code)->assertJsonPath('data.code', 'SAME-CODE');
            $this->getJson('/api/public/v1/organizations/'.$tree->organization_id.'/trees/'.$tree->id)->assertNotFound();
        }
        $this->getJson('/api/public/v1/trees')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_organization_public_identifier_survives_private_code_change(): void
    {
        $this->publishTree();
        $public = $this->organization->public_code;
        $this->organization->forceFill(['code' => 'CHANGED-PRIVATE-CODE'])->save();
        $this->getJson('/api/public/v1/organizations/'.$public.'/trees/'.$this->tree->code)
            ->assertOk()->assertJsonPath('data.organization.code', $public);
        $this->getJson('/api/public/v1/organizations/CHANGED-PRIVATE-CODE/trees/'.$this->tree->code)->assertNotFound();
    }

    #[DataProvider('forbiddenFilters')]
    public function test_private_or_invalid_filters_are_rejected_without_internal_errors(array $filter): void
    {
        $response = $this->getJson('/api/public/v1/trees?'.http_build_query($filter))->assertUnprocessable();
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
    }

    public static function forbiddenFilters(): array
    {
        return [
            [['scope' => 'national']], [['trashed' => 'only']], [['include_deleted' => '1']],
            [['maintenance_priority' => 'urgent']], [['has_pending_maintenance' => '1']],
            [['maintenance_status' => 'scheduled']], [['sort' => 'created_by']],
            [['organization_id' => '00000000-0000-4000-8000-000000000000']], [['q' => ['nested']]],
            [['page' => 1001]], [['direction' => 'desc;drop table trees']],
            [['physical_condition_ids' => [str_repeat('a', 201)]]],
        ];
    }

    #[DataProvider('mutationMethods')]
    public function test_public_routes_are_read_only(string $method): void
    {
        $before = $this->tree->fresh()->is_public;
        $this->json($method, '/api/public/v1/trees', ['is_public' => true])->assertStatus(405);
        $this->assertSame($before, $this->tree->fresh()->is_public);
    }

    public static function mutationMethods(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    public function test_private_routes_keep_their_authentication_requirements(): void
    {
        $this->getJson('/api/v1/map/trees?'.http_build_query($this->bbox()))->assertUnauthorized();
        $this->get('/map')->assertRedirect(route('login'));
        $this->get('/trees')->assertRedirect(route('login'));
        $this->get('/publication')->assertRedirect(route('login'));
    }

    public function test_api_failures_hide_sql_paths_and_secrets_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $this->mock(PublicTreeRepository::class, fn ($mock) => $mock->shouldReceive('indicators')
            ->andThrow(new RuntimeException('SQLSTATE /var/www/private password=PRIVATE-SENTINEL')));
        $response = $this->getJson('/api/public/v1/indicators')->assertStatus(503)
            ->assertJsonPath('message', 'No fue posible consultar la información pública. Inténtalo de nuevo en unos momentos.');
        $this->assertSame(['message'], array_keys($response->json()));
        $this->assertStringNotContainsString('PRIVATE-SENTINEL', $response->getContent());
    }

    public function test_public_rate_limit_is_enforced_without_identity_or_ip_disclosure(): void
    {
        $key = 'public-test:'.Str::uuid();
        RateLimiter::for('public.api', fn () => Limit::perMinute(1)->by($key));
        $this->getJson('/api/public/v1/indicators')->assertOk();
        $response = $this->getJson('/api/public/v1/indicators')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
        $this->assertStringNotContainsString($key, $response->getContent());
    }

    public function test_pagination_preserves_public_filters_and_never_returns_thousands_of_trees(): void
    {
        for ($index = 0; $index < 23; $index++) {
            $this->publishTree($this->point(-73.65, 4.14, ['code' => sprintf('PAGE-%03d', $index)]));
        }
        $first = $this->getJson('/api/public/v1/trees?code=PAGE&sort=code&direction=asc')->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('total', 23)->assertJsonPath('data.0.code', 'PAGE-000');
        $next = $first->json('next_page_url');
        $this->assertStringContainsString('code=PAGE', $next);
        $this->assertStringNotContainsString($this->organization->id, $next);
        $this->getJson($next)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.code', 'PAGE-020');
    }

    /** Recorre claves porque una fuga puede estar anidada dentro de un historial o un pivote. */
    private function assertPublicProjection(array $data): void
    {
        $forbidden = ['user_id', 'uploaded_by', 'created_by', 'updated_by', 'deleted_by', 'assigned_to',
            'creator', 'updater', 'uploader', 'assignee', 'email', 'phone', 'audit', 'revisions',
            'metadata', 'exif', 'disk', 'path', 'checksum_sha256', 'original_name', 'pivot',
            'coordinate_raw', 'gps_model', 'accuracy_m', 'general_observations', 'notes', 'result', 'lock_version',
            'access_token', 'refresh_token', 'token', 'session', 'organization_id', 'tree_id'];
        foreach ($data as $key => $value) {
            $this->assertNotContains((string) $key, $forbidden);
            if (is_array($value)) {
                $this->assertPublicProjection($value);
            }
        }
    }
}
