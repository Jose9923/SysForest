<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Repositories\AuditReadRepository;
use App\Repositories\RoleAssignmentRepository;
use App\Repositories\SecurityEventRepository;
use App\Services\AuditReadService;
use App\Services\TenantContextService;
use App\Services\TreeInspectionService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\InspectionTestCase;

class AuditReadTest extends InspectionTestCase
{
    public function test_organization_timeline_and_details_never_include_another_tenant_or_national_events(): void
    {
        $own = $this->event();
        $other = $this->event($this->anotherOrganization()->id);
        $national = app(SecurityEventRepository::class)->record('audit.national', $this->admin);
        $data = app(AuditReadService::class)->index($this->admin, ['event' => 'audit.fixture']);
        $this->assertSame('organization', $data['scope']->kind);
        $this->assertSame([$own->id], $data['entries']->pluck('id')->all());
        $this->assertCount(0, $data['organizations']);
        foreach ([$other, $national] as $forbidden) {
            try {
                app(AuditReadService::class)->show($this->admin, 'events', $forbidden->id, []);
                $this->fail('Una entrada de otro alcance fue visible.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_national_admin_can_explicitly_broaden_scope_and_read_inactive_organization_history(): void
    {
        $this->allowNationalManagement($this->admin);
        $other = $this->anotherOrganization();
        $event = $this->event($other->id);
        $other->forceFill(['active' => false])->save();
        $data = app(AuditReadService::class)->index($this->admin, ['scope' => 'national', 'organization_id' => $other->id]);
        $this->assertSame([$event->id], $data['entries']->pluck('id')->all());
        $this->assertTrue($data['canViewNational']);
        $this->assertSame('national', $data['scope']->kind);
        $this->assertSame($event->id, app(AuditReadService::class)->show($this->admin, 'events', $event->id, ['scope' => 'national'])['entry']['id']);
        $this->expectException(ModelNotFoundException::class);
        app(AuditReadService::class)->show($this->admin, 'events', $event->id, ['scope' => 'organization']);
    }

    #[DataProvider('deniedRoles')]
    public function test_official_and_department_admin_cannot_read_audit(string $role): void
    {
        $actor = User::factory()->create();
        if ($role === 'OFFICIAL') {
            $this->member($actor, $this->organization);
        } else {
            app(RoleAssignmentRepository::class)->grantDepartment($actor->id, $this->organization->municipality->department_id);
        }
        $this->actingTenant($actor, $this->organization)->get('/audit')->assertForbidden();
        $this->get('/audit?scope=national')->assertForbidden();
        app(TenantContextService::class)->activate($actor, $this->organization->id);
        $this->expectException(AuthorizationException::class);
        app(AuditReadService::class)->index($actor, []);
    }

    public static function deniedRoles(): array
    {
        return [['OFFICIAL'], ['DEPARTMENT_ADMIN']];
    }

    public function test_revoke_after_query_prevents_returning_the_loaded_data(): void
    {
        $this->event();
        $repository = app(AuditReadRepository::class);
        $this->mock(AuditReadRepository::class)->shouldReceive('paginate')->once()
            ->andReturnUsing(function ($scope, $filters) use ($repository) {
                $page = $repository->paginate($scope, $filters);
                DB::table('organization_role_assignments')->whereIn('membership_id', function ($query): void {
                    $query->select('id')->from('organization_memberships')->where('user_id', $this->admin->id);
                })->update(['active' => false]);

                return $page;
            });
        $this->expectException(AuthorizationException::class);
        app(AuditReadService::class)->index($this->admin, []);
    }

    public function test_filters_use_literal_actor_name_exact_action_resource_and_utc_dates(): void
    {
        $this->admin->forceFill(['name' => 'Audit_% literal'])->save();
        $event = $this->event();
        $this->event($this->organization->id, 'audit.other');
        $data = app(AuditReadService::class)->index($this->admin, [
            'actor' => '_%', 'event' => 'audit.fixture', 'resource_type' => 'tree', 'resource_id' => $this->tree->id,
            'date_from' => now('UTC')->toDateString(), 'date_to' => now('UTC')->toDateString(),
        ]);
        $this->assertSame([$event->id], $data['entries']->pluck('id')->all());
        $this->assertSame(0, app(AuditReadService::class)->index($this->admin, ['actor' => 'NO MATCH'])['entries']->total());
        $this->assertSame(0, app(AuditReadService::class)->index($this->admin, ['date_from' => '2000-01-01', 'date_to' => '2000-01-01'])['entries']->total());
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_cannot_reach_dynamic_sql(array $filters): void
    {
        $this->expectException(ValidationException::class);
        app(AuditReadService::class)->index($this->admin, $filters);
    }

    public static function invalidFilters(): array
    {
        return [[['actor' => ['bad']]], [['scope' => 'department']], [['resource_type' => 'users; DROP TABLE users']],
            [['sort' => 'id']], [['date_from' => '2026-02-30']], [['date_from' => '0000-01-01']], [['date_to' => '0000-12-31']], [['date_from' => '2026-09-29', 'date_to' => '2026-09-01']],
            [['organization_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']], [['page' => 0]], [['event' => 'x OR true']]];
    }

    public function test_pagination_is_stable_when_timestamps_are_equal(): void
    {
        $this->freezeTime();
        for ($index = 0; $index < 31; $index++) {
            $this->event();
        }
        $first = app(AuditReadService::class)->index($this->admin, ['event' => 'audit.fixture']);
        $second = app(AuditReadService::class)->index($this->admin, ['event' => 'audit.fixture', 'page' => 2]);
        $this->assertCount(25, $first['entries']);
        $this->assertCount(6, $second['entries']);
        $ids = array_merge($first['entries']->pluck('id')->all(), $second['entries']->pluck('id')->all());
        $this->assertCount(31, array_unique($ids));
        $this->assertStringContainsString('scope=organization', $first['entries']->url(2));
    }

    public function test_legacy_revision_is_available_but_explicit_link_prevents_duplicate_new_revision(): void
    {
        $inspection = $this->capture();
        app(TreeInspectionService::class)->update($this->admin, $inspection->id, ['lock_version' => 1, 'cap_cm' => '200']);
        $event = SecurityEvent::query()->where('event', 'inspection.updated')->where('resource_id', $inspection->id)->sole();
        $legacyId = (string) Str::uuid();
        DB::table('inspection_revisions')->insert([
            'id' => $legacyId, 'organization_id' => $this->organization->id, 'inspection_id' => $inspection->id,
            'changed_by' => $this->admin->id, 'changed_at' => now(), 'version' => 3, 'event' => 'inspection.updated',
            'old_values' => json_encode(['measurements' => ['cap_cm' => '199.000000']]),
            'new_values' => json_encode(['measurements' => ['cap_cm' => '200.000000']]),
        ]);
        $data = app(AuditReadService::class)->index($this->admin, ['resource_id' => $inspection->id, 'event' => 'inspection.updated']);
        $this->assertEqualsCanonicalizing([$event->id, $legacyId], $data['entries']->pluck('id')->all());
        $detail = app(AuditReadService::class)->show($this->admin, 'inspections', $legacyId, [])['entry'];
        $this->assertTrue($detail['has_snapshots']);
        $this->assertSame('Mediciones · CAP (cm)', $detail['changes'][0]['label']);
        $this->assertSame('199.000000', $detail['changes'][0]['before']);
    }

    public function test_untrusted_historical_payload_is_projected_and_html_is_escaped(): void
    {
        $id = (string) Str::uuid();
        DB::table('security_events')->insert([
            'id' => $id, 'event' => 'audit.fixture', 'actor_user_id' => $this->admin->id, 'organization_id' => $this->organization->id,
            'created_at' => now(), 'resource_type' => 'tree', 'resource_id' => $this->tree->id,
            'before_values' => json_encode(['external_number' => null, 'is_protected' => false, 'password' => 'SENSITIVE-PASSWORD']),
            'after_values' => json_encode(['external_number' => '<script>alert(42)</script>', 'is_protected' => true, 'path' => 'SENSITIVE-PATH']),
            'metadata' => json_encode(['token' => 'SENSITIVE-TOKEN', 'reason' => '<img src=x onerror=alert(1)>']),
            'context' => json_encode(['request_id' => 'SENSITIVE-INVALID-CONTEXT']),
        ]);
        $response = $this->actingTenant($this->admin, $this->organization)->get('/audit/events/'.$id.'?scope=organization');
        $response->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(42)</script>', false)
            ->assertDontSee('SENSITIVE-')->assertSee('Nulo')->assertSee('No')->assertSee('Sí');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_read_routes_require_login_and_do_not_offer_mutation_or_public_aliases(): void
    {
        $event = $this->event();
        $this->get('/audit')->assertRedirect('/login');
        $this->get('/portal/audit')->assertNotFound();
        $this->actingTenant($this->admin, $this->organization)->get('/audit')->assertOk()->assertSee('Auditoría');
        $this->get('/audit/events/'.Str::uuid())->assertNotFound();
        $this->get('/audit/unknown/'.$event->id)->assertNotFound();
        $this->delete('/audit/events/'.$event->id)->assertStatus(405);
    }

    public function test_legacy_security_event_is_honest_about_missing_snapshots(): void
    {
        $event = app(SecurityEventRepository::class)->record('legacy.fixture', $this->admin, $this->organization->id);
        $detail = app(AuditReadService::class)->show($this->admin, 'events', $event->id, [])['entry'];
        $this->assertFalse($detail['has_snapshots']);
        $this->assertSame([], $detail['changes']);
        $this->assertNull($detail['resource_id']);
        $this->assertSame('Evento de seguridad', $detail['resource_label']);
    }

    public function test_global_admin_without_tenant_can_read_audit(): void
    {
        $this->allowNationalManagement($this->admin);
        app(TenantContext::class)->clear();
        $this->assertSame('national', app(AuditReadService::class)->index($this->admin, [])['scope']->kind);
        $this->actingAs($this->admin)->get('/audit?scope=national')->assertOk();
    }

    private function event(?string $organizationId = null, string $event = 'audit.fixture'): SecurityEvent
    {
        return app(SecurityEventRepository::class)->recordChange(
            $event,
            $this->admin,
            $organizationId ?? $this->organization->id,
            'tree',
            $this->tree->id,
            ['external_number' => null],
            ['external_number' => '000042']
        );
    }
}
