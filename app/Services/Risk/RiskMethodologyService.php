<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Domain\Risk\PhytosanitaryRisk;
use App\Models\Risk\RiskMethodology;
use App\Models\User;
use App\Repositories\Risk\RiskRepository;
use App\Repositories\Auth\SecurityEventRepository;
use App\Repositories\Users\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\Forestry\ForestryAccessService;

/** Publicar una metodología crea una versión institucional sin modificar las anteriores. */
class RiskMethodologyService
{
    public function __construct(
        private ForestryAccessService $access,
        private RiskRepository $risks,
        private UserRepository $users,
        private SecurityEventRepository $events,
    ) {
    }

    public function template(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/phytosanitary-risk.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function initialize(string $organizationId): RiskMethodology
    {
        return DB::transaction(function () use ($organizationId): RiskMethodology {
            $this->risks->lockOrganization($organizationId);

            return $this->risks->active($organizationId)
                ?? $this->risks->publish($organizationId, 'Prioridad de Gestión Fitosanitaria', $this->template(), null);
        });
    }

    public function page(User $actor): array
    {
        $organization = $this->access->organization($actor);
        $this->access->authorize($actor, 'risk.view');
        $method = $this->risks->active($organization->id);

        return ['organization' => $organization, 'methodology' => $method,
            'configuration' => $method?->configuration ?? $this->template(),
            'settingsVersion' => $this->risks->settings($organization->id)?->lock_version ?? 0,
            'conditions' => $this->risks->conditions(), 'versions' => $this->risks->versions($organization->id),
            'canManage' => $this->access->allows($actor, 'risk.manage')];
    }

    public function publish(User $actor, array $input): void
    {
        DB::transaction(function () use ($actor, $input): void {
            $actor = $this->users->findForUpdate((int) $actor->id);
            $organization = $this->access->organization($actor, true);
            $this->access->authorize($actor, 'risk.manage');
            $this->risks->lockOrganization($organization->id);
            $data = Validator::make($input, [
                'name' => ['required', 'string', 'max:150'], 'lock_version' => ['required', 'integer', 'min:0'],
                'conditions' => ['required', 'array', 'min:1', 'max:100'],
                'conditions.*' => ['required', 'array:enabled,weight,minimum,component'],
                'conditions.*.enabled' => ['required', 'boolean'],
                'conditions.*.weight' => ['required', 'integer', 'between:0,4'],
                'conditions.*.minimum' => ['required', 'integer', 'between:0,4'],
                'conditions.*.component' => ['required', Rule::in([...PhytosanitaryRisk::COMPONENTS, 'PHT_GENERAL'])],
                'matrix' => ['required', 'array', 'size:5'], 'matrix.*' => ['required', 'array', 'size:4'],
                'matrix.*.*' => ['required', 'integer', 'between:1,4'],
                'conflicts' => ['nullable', 'array', 'max:100'],
                'conflicts.*' => ['array:enabled,left,right'],
                'conflicts.*.enabled' => ['required', 'boolean'],
                'conflicts.*.left' => ['nullable', 'string', 'max:200'],
                'conflicts.*.right' => ['nullable', 'array', 'max:100'],
                'conflicts.*.right.*' => ['string', 'max:200'],
                'warnings' => ['nullable', 'array', 'max:100'],
                'warnings.*' => ['array:enabled,kind,condition,unless,message'],
                'warnings.*.enabled' => ['required', 'boolean'],
                'warnings.*.kind' => ['nullable', 'string', 'max:30'],
                'warnings.*.condition' => ['nullable', 'string', 'max:200'],
                'warnings.*.unless' => ['nullable', 'array', 'max:100'],
                'warnings.*.unless.*' => ['string', 'max:200'],
                'warnings.*.message' => ['nullable', 'string', 'max:300'],
            ], ['required' => 'Completa :attribute.', 'between' => 'Valor fuera del intervalo permitido en :attribute.',
                'in' => 'Selección no válida en :attribute.', 'array' => 'Estructura no válida en :attribute.',
                'matrix.size' => 'La matriz debe contener cinco severidades, de 0 a 4.',
                'matrix.*.size' => 'Cada severidad debe contener cuatro extensiones, de E0 a E3.',
                'matrix.*.*.integer' => 'Cada prioridad de la matriz debe ser un número entero de 1 a 4.'])->validate();
            $setting = $this->risks->settings($organization->id);
            if ((int) $data['lock_version'] !== ($setting->lock_version ?? 0)) {
                throw ValidationException::withMessages(['lock_version' => 'La parametrización cambió. Recarga antes de publicar.']);
            }
            $references = $this->risks->conditions()->keyBy('code');
            $old = $this->risks->active($organization->id);
            $previous = $old?->configuration ?? $this->template();
            $configuration = ['matrix' => $this->validatedMatrix($data['matrix']),
                'conditions' => [], 'conflicts' => $this->enabledRules($data['conflicts'] ?? []),
                'warnings' => $this->enabledRules($data['warnings'] ?? [])];
            foreach ($data['conditions'] as $code => $rule) {
                if (!isset($references[$code])) {
                    throw ValidationException::withMessages(['conditions' => 'Una condición no pertenece al catálogo fitosanitario.']);
                }
                if (!(bool) $rule['enabled']) {
                    continue;
                }
                $reference = $references[$code];
                if ((!$reference->active || !$reference->category->active) && !isset($previous['conditions'][$code])) {
                    throw ValidationException::withMessages(['conditions' => 'No se puede incorporar una condición inactiva.']);
                }
                $configuration['conditions'][$code] = ['label' => $reference->name,
                    'column' => $previous['conditions'][$code]['column'] ?? null,
                    'weight' => (int) $rule['weight'], 'minimum' => (int) $rule['minimum'], 'component' => $rule['component']];
            }
            if ($configuration['conditions'] === []) {
                throw ValidationException::withMessages(['conditions' => 'Incorpora al menos una condición.']);
            }
            $this->validateRules($configuration);
            $method = $this->risks->publish($organization->id, $data['name'], $configuration, (int) $actor->id);
            $this->events->recordChange(
                'risk.methodology.published',
                $actor,
                $organization->id,
                'risk_methodology',
                $method->id,
                ['version' => $old?->version, 'configuration_json' => $old ? json_encode($old->configuration, JSON_UNESCAPED_UNICODE) : null],
                ['version' => $method->version, 'name' => $method->name, 'configuration_json' => json_encode($method->configuration, JSON_UNESCAPED_UNICODE)],
                ['resource_id' => $method->id]
            );
        }, 3);
    }

    /** Se valida solo al publicar; las versiones históricas se conservan sin reinterpretarlas. */
    private function validatedMatrix(array $matrix): array
    {
        $severities = array_keys($matrix);
        sort($severities);
        if ($severities !== range(0, 4)) {
            throw ValidationException::withMessages(['matrix' => 'La matriz debe contener exactamente las severidades de 0 a 4.']);
        }
        $normalized = [];
        foreach (range(0, 4) as $severity) {
            $extensions = array_keys($matrix[$severity]);
            sort($extensions);
            if ($extensions !== range(0, 3)) {
                throw ValidationException::withMessages(['matrix' => 'Cada severidad debe contener exactamente las extensiones E0, E1, E2 y E3.']);
            }
            foreach (range(0, 3) as $extension) {
                $normalized[$severity][$extension] = (int) $matrix[$severity][$extension];
            }
        }
        $errors = [];
        foreach ($normalized as $severity => $row) {
            foreach ($row as $extension => $priority) {
                if ($severity > 0 && $priority < $normalized[$severity - 1][$extension]) {
                    $errors['matrix.'.$severity.'.'.$extension][] = 'La prioridad no puede disminuir al aumentar la severidad de '.($severity - 1).' a '.$severity.' en E'.$extension.'.';
                }
                if ($extension > 0 && $priority < $row[$extension - 1]) {
                    $errors['matrix.'.$severity.'.'.$extension][] = 'La prioridad no puede disminuir al aumentar la extensión de E'.($extension - 1).' a E'.$extension.' con severidad '.$severity.'.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function enabledRules(array $rules): array
    {
        $enabled = [];
        foreach ($rules as $rule) {
            if ((bool) $rule['enabled']) {
                unset($rule['enabled']);
                $enabled[] = $rule;
            }
        }

        return $enabled;
    }

    private function validateRules(array $configuration): void
    {
        $codes = array_keys($configuration['conditions']);
        $validator = Validator::make($configuration, [
            'conflicts.*' => ['array:left,right'],
            'conflicts.*.left' => ['required', Rule::in($codes)],
            'conflicts.*.right' => ['required', 'array', 'min:1', 'max:100'],
            'conflicts.*.right.*' => ['required', Rule::in($codes)],
            'warnings.*' => ['array:kind,condition,unless,message'],
            'warnings.*.kind' => ['required', 'in:healthy_severe,insect_unspecified'],
            'warnings.*.condition' => ['required', Rule::in($codes)],
            'warnings.*.unless' => ['sometimes', 'array', 'max:100'],
            'warnings.*.unless.*' => ['required', Rule::in($codes)],
            'warnings.*.message' => ['required', 'string', 'max:300'],
        ], ['required' => 'Completa los campos de cada regla activada.',
            'in' => 'Las reglas solo pueden usar condiciones incorporadas a esta metodología. Retira sus reglas antes de excluir una condición.',
            'array' => 'Selecciona las condiciones relacionadas con la regla.',
            'string' => 'El mensaje de advertencia debe ser texto.',
            'max' => 'La regla supera el límite permitido.']);
        $validator->validate();
        foreach ($configuration['conflicts'] as $rule) {
            if (count(array_unique($rule['right'])) !== count($rule['right'])) {
                throw ValidationException::withMessages(['conflicts' => 'No repitas condiciones dentro de una misma contradicción.']);
            }
            if (in_array($rule['left'], $rule['right'], true)) {
                throw ValidationException::withMessages(['conflicts' => 'Una condición no puede contradecirse a sí misma.']);
            }
        }
    }
}
