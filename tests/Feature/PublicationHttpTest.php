<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PublicationService;
use App\Services\TreeService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MaintenanceTestCase;

class PublicationHttpTest extends MaintenanceTestCase
{
    #[DataProvider('roles')]
    public function test_http_requires_real_publication_capability(string $role, bool $allowed): void
    {
        $actor = $this->maintenanceActor($role);
        $this->actingTenant($actor, $this->organization);
        $page = $this->get(route('publication.index'));
        $response = $this->patchJson(
            route('publication.trees.update', $this->tree->id),
            ['is_public' => true, 'publication_version' => 1]
        );
        if ($allowed) {
            $page->assertOk()->assertSee('Administrar publicación');
            $response->assertRedirect(route('publication.index', ['kind' => 'trees']));
            $this->assertTrue($this->tree->fresh()->is_public);
        } else {
            $page->assertForbidden();
            $response->assertForbidden();
            $this->assertFalse($this->tree->fresh()->is_public);
        }
    }

    public static function roles(): array
    {
        return [['OFFICIAL', false], ['DEPARTMENT_ADMIN', false],
            ['ORGANIZATION_ADMIN', true], ['SUPER_ADMIN', true]];
    }

    #[DataProvider('kinds')]
    public function test_admin_panel_renders_each_domain_and_records_a_change(string $kind): void
    {
        $record = $this->subject($kind);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('publication.index', ['kind' => $kind]))->assertOk()
            ->assertSee('Historial privado de publicación')->assertSee('publication_version');
        $this->patchJson($this->target($kind, $record->id), ['is_public' => true, 'publication_version' => 1])
            ->assertRedirect();
        $this->get(route('publication.index', ['kind' => $kind]))->assertOk()
            ->assertSee('Autorización guardada');
        $this->assertTrue($record->fresh()->is_public);
        $this->assertDatabaseCount('publication_revisions', 1);
    }

    public static function kinds(): array
    {
        return [['organizations'], ['inventories'], ['trees'], ['inspections'], ['evidences']];
    }

    #[DataProvider('children')]
    public function test_foreign_child_ids_return_404_without_modifying_foreign_flags(string $kind): void
    {
        $record = $this->subject($kind);
        $other = $this->anotherOrganization($this->organization);
        $this->member($this->admin, $other);
        $this->allowManagement($this->admin);
        $this->actingTenant($this->admin, $other);
        $this->patchJson($this->target($kind, $record->id), ['is_public' => true, 'publication_version' => 1])->assertNotFound();
        $this->assertFalse($record->fresh()->is_public);
        $this->assertDatabaseCount('publication_revisions', 0);
    }

    public static function children(): array
    {
        return [['inventories'], ['trees'], ['inspections'], ['evidences']];
    }

    public function test_anonymous_clients_cannot_read_or_mutate_publication_administration(): void
    {
        $this->get(route('publication.index'))->assertRedirect(route('login'));
        $this->patchJson(
            route('publication.trees.update', $this->tree->id),
            ['is_public' => true, 'publication_version' => 1]
        )->assertUnauthorized();
        $this->assertFalse($this->tree->fresh()->is_public);
    }

    public function test_csrf_is_enforced_on_real_browser_publication(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class ($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->actingTenant($this->admin, $this->organization);
        $page = $this->get(route('publication.index', ['kind' => 'trees']))->assertOk();
        preg_match('/name="_token"\\s+value="([^"]+)"/', $page->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $url = route('publication.trees.update', $this->tree->id);
        $this->patchJson($url, ['is_public' => true, 'publication_version' => 1])->assertStatus(419);
        $this->patchJson($url, ['is_public' => true, 'publication_version' => 1,
            '_token' => html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8')])->assertRedirect();
        $this->assertTrue($this->tree->fresh()->is_public);
    }

    public function test_stale_http_request_returns_409_and_preserves_first_publication(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $url = route('publication.trees.update', $this->tree->id);
        $this->patchJson($url, ['is_public' => true, 'publication_version' => 1])->assertRedirect();
        $this->patchJson($url, ['is_public' => false, 'publication_version' => 1])->assertStatus(409);
        $this->assertTrue($this->tree->fresh()->is_public);
        $this->assertDatabaseCount('publication_revisions', 1);
    }

    #[DataProvider('forgedFields')]
    public function test_publication_rejects_identity_and_provenance_from_client(string $field, mixed $value): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $this->patchJson(
            route('publication.trees.update', $this->tree->id),
            ['is_public' => true, 'publication_version' => 1, $field => $value]
        )
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertFalse($this->tree->fresh()->is_public);
    }

    public static function forgedFields(): array
    {
        return [['organization_id', 'spoof'], ['public_code', 'FORGED'], ['public_id', 'FORGED'],
            ['updated_by', 1], ['lock_version', 1], ['public_caption', 'Texto fuera de fotografías']];
    }

    public function test_public_caption_is_escaped_in_form_and_private_audit(): void
    {
        $evidence = $this->attachEvidence(['caption' => 'Privado no copiable']);
        $this->actingTenant($this->admin, $this->organization);
        $payload = '<script>alert("public-caption")</script>';
        $this->patchJson(
            route('publication.evidences.update', $evidence->id),
            ['is_public' => true, 'publication_version' => 1, 'public_caption' => $payload]
        )->assertRedirect();
        $response = $this->get(route('publication.index', ['kind' => 'evidences']))->assertOk();
        $response->assertDontSee($payload, false)->assertSee(e($payload), false)->assertDontSee('Privado no copiable');
    }

    public function test_index_is_paginated_and_does_not_list_other_organizations(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            app(TreeService::class)->create($this->admin, [
                'inventory_ids' => [$this->inventory->id], 'current_status' => 'active',
            ]);
        }
        $foreign = $this->anotherOrganization($this->organization);
        $this->actingTenant($this->admin, $this->organization);
        $page = $this->get(route('publication.index', ['kind' => 'trees']))->assertOk();
        $page->assertViewHas('records', fn ($records) => $records->count() === 20 && $records->total() === 22);
        $page->assertDontSee($foreign->name);
    }

    public function test_filter_zero_and_one_distinguish_private_and_authorized_records(): void
    {
        app(PublicationService::class)->tree($this->admin, $this->tree->id, ['is_public' => true, 'publication_version' => 1]);
        $private = app(TreeService::class)->create($this->admin, [
            'inventory_ids' => [$this->inventory->id], 'current_status' => 'active']);
        $this->actingTenant($this->admin, $this->organization);
        $this->get(route('publication.index', ['kind' => 'trees', 'is_public' => 1]))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->items()[0]['id'] === $this->tree->id);
        $this->get(route('publication.index', ['kind' => 'trees', 'is_public' => 0]))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->items()[0]['id'] === $private->id);
    }

    public function test_ordinary_tree_endpoint_cannot_publish_even_for_admin(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        $this->putJson(route('trees.update', $this->tree->id), [
            'lock_version' => $this->tree->lock_version, 'is_public' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_public');
        $this->assertFalse($this->tree->fresh()->is_public);
    }

    public function test_membership_revocation_invalidates_already_authenticated_browser(): void
    {
        $this->actingTenant($this->admin, $this->organization);
        DB::table('organization_memberships')->where('user_id', $this->admin->id)
            ->where('organization_id', $this->organization->id)->update(['active' => false]);
        $this->patchJson(
            route('publication.trees.update', $this->tree->id),
            ['is_public' => true, 'publication_version' => 1]
        )->assertForbidden();
        $this->assertDatabaseCount('publication_revisions', 0);
    }

    private function subject(string $kind): \Illuminate\Database\Eloquent\Model
    {
        return match ($kind) {
            'organizations' => $this->organization, 'inventories' => $this->inventory,
            'trees' => $this->tree, 'inspections' => $this->capture(), 'evidences' => $this->attachEvidence(),
        };
    }

    private function target(string $kind, string $id): string
    {
        return $kind === 'organizations' ? route('publication.organizations.update') : route('publication.'.$kind.'.update', $id);
    }
}
