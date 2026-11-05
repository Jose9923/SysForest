<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
use App\Auditing\AuditCatalog;
use App\Repositories\Audit\AuditReadRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class AuditReadService
{
    public function __construct(
        private AuditAccessService $access,
        private AuditFilterService $filters,
        private AuditReadRepository $records,
        private AuditPresentationService $presentation,
    ) {
    }

    public function index(User $actor, array $input): array
    {
        $validated = $this->filters->validate($input);
        $scope = $this->access->resolve($actor, $validated['scope'] ?? null);
        $filters = $this->filters->resolve($validated, $scope);
        $entries = $this->records->paginate($scope, $filters);
        $entries->through(fn (object $record) => $this->presentation->row($record) + [
            'detail_url' => route('audit.show', ['source' => $record->source, 'entry' => $record->id, 'scope' => $scope->kind]),
        ])->appends($filters);
        $organizations = $this->records->organizations($scope);
        $options = $this->records->options($scope);
        $eventOptions = $options['events']->mapWithKeys(fn (string $event): array => [$event => AuditCatalog::moduleLabel($event).' · '.AuditCatalog::eventLabel($event).' ('.$event.')']);
        $this->access->reauthorize($actor, $scope);

        return compact('scope', 'filters', 'entries', 'organizations', 'eventOptions') + [
            'userOptions' => $options['users'], 'moduleOptions' => AuditCatalog::modules(),
            'exportLimit' => max(1, min(50000, (int) config('audit.max_export_rows', 50000))),
            'storageNotice' => $this->records->notice(), 'canViewNational' => $this->access->canViewNational($actor), 'resourceTypes' => AuditPresentationService::TYPES,
        ];
    }

    public function show(User $actor, string $source, string $id, array $input): array
    {
        $validated = $this->filters->validate($input);
        $scope = $this->access->resolve($actor, $validated['scope'] ?? null);
        if (!in_array($source, AuditReadRepository::SOURCES, true) || !Str::isUuid($id)) {
            throw new ModelNotFoundException();
        }
        $this->filters->resolve($validated, $scope);
        $record = $this->records->find($scope, $source, strtolower($id));
        if ($record === null) {
            throw new ModelNotFoundException();
        }
        $entry = $this->presentation->detail($record);
        $this->access->reauthorize($actor, $scope);

        return compact('scope', 'entry') + ['storageNotice' => $this->records->notice(), 'back_url' => route('audit.index', ['scope' => $scope->kind])];
    }
}
