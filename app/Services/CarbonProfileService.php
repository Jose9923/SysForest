<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\CarbonRepository;
use App\Repositories\UserRepository;
use App\Repositories\SecurityEventRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Referencias y validación local propias de cada entidad; nunca altera la taxonomía nacional. */
class CarbonProfileService
{
    public function __construct(
        private ForestryAccessService $access,
        private CarbonRepository $repository,
        private UserRepository $users,
        private SecurityEventRepository $events,
        private CarbonEstimationService $estimates
    ) {
    }

    public function page(User $actor): array
    {
        $organization = $this->access->organization($actor);
        $this->access->authorize($actor, 'inspections.view');
        $profiles = $this->repository->profiles($organization->id);

        return ['organization' => $organization, 'species' => $this->repository->species(), 'profiles' => $profiles,
            'settingsVersion' => $profiles->first()?->id ?? 0, 'canManage' => $this->access->allows($actor, 'carbon.manage')];
    }

    public function publish(User $actor, array $input): void
    {
        DB::transaction(function () use ($actor, $input): void {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $this->access->authorize($actor, 'carbon.manage');
            $this->repository->lockOrganization($organization->id);
            $data = Validator::make($input, [
                'species_id' => ['required', 'uuid', Rule::in($this->repository->species()->where('active', true)->modelKeys())],
                'settings_version' => ['required', 'integer', 'min:0'], 'applicable' => ['required', 'boolean'],
                'wood_density' => ['nullable', 'required_if:applicable,1', 'numeric', 'gt:0', 'max:2', 'decimal:0,6'],
                'max_diameter_cm' => ['nullable', 'required_if:applicable,1', 'numeric', 'min:5', 'max:10000', 'decimal:0,3'],
                'max_height_m' => ['nullable', 'required_if:applicable,1', 'numeric', 'gt:0', 'max:1000', 'decimal:0,3'],
                'reference' => ['required', 'string', 'max:3000', 'regex:/\S/u'],
                'justification' => ['required', 'string', 'max:5000', 'regex:/\S/u'],
                'validated' => ['accepted'],
            ], ['required' => 'Completa :attribute.', 'required_if' => 'Completa :attribute para aplicar el método.',
                'accepted' => 'Confirma la revisión profesional de la referencia y de la aplicabilidad local.',
                'numeric' => 'El campo :attribute debe ser numérico.', 'decimal' => 'Revisa los decimales de :attribute.',
                'gt' => 'El campo :attribute debe ser mayor que cero.', 'min' => 'El campo :attribute no alcanza el mínimo permitido.',
                'max' => 'El campo :attribute excede el límite permitido.', 'in' => 'Selecciona una especie activa.',
            ], ['species_id' => 'especie', 'wood_density' => 'densidad básica', 'max_diameter_cm' => 'DAP máximo',
                'max_height_m' => 'altura máxima', 'reference' => 'fuente de densidad', 'justification' => 'justificación',
                'settings_version' => 'versión de referencias', 'applicable' => 'aplicabilidad'])->validate();
            if ((int) $data['settings_version'] !== (int) ($this->repository->profiles($organization->id)->first()?->id ?? 0)) {
                throw ValidationException::withMessages(['settings_version' => 'Las referencias cambiaron. Recarga antes de publicar.']);
            }
            unset($data['settings_version'], $data['validated']);
            $profile = $this->repository->publish($organization->id, (int) $actor->id, $data + ['validation_status' => 'professional', 'source_package' => null]);
            $this->events->recordChange(
                'carbon.profile.published',
                $actor,
                $organization->id,
                'carbon_profile',
                (string) $profile->id,
                [],
                ['configuration_json' => json_encode($profile->attributesToArray(), JSON_UNESCAPED_UNICODE)],
                ['resource_id' => (string) $profile->id]
            );
        }, 3);
    }

    public function refresh(User $actor): int
    {
        // La autorización se mantiene bloqueada durante la actualización institucional completa.
        return DB::transaction(function () use ($actor): int {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $this->access->authorize($actor, 'carbon.manage');
            $this->repository->lockOrganization($organization->id);
            $count = $this->estimates->backfill($organization->id);
            $this->events->recordChange(
                'carbon.estimates.refreshed',
                $actor,
                $organization->id,
                'carbon_profile',
                $organization->id,
                [],
                ['configuration_json' => json_encode(['estimates_added' => $count])],
                ['resource_id' => $organization->id]
            );

            return $count;
        }, 3);
    }
}
