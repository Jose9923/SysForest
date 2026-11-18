<?php

declare(strict_types=1);

namespace App\Repositories\Audit;

use App\Auditing\AuditRecordProjection;
use App\Auditing\AuditCatalog;
use App\DTOs\Audit\AuditScope;
use App\Repositories\Identifiers\IdentifierRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MongoDB\Client;
use MongoDB\Collection as MongoCollection;
use MongoDB\Driver\Exception\BulkWriteException;
use RuntimeException;

/** Cliente privado de sólo inserción/lectura; los filtros públicos nunca son operadores Mongo. */
class MongoAuditRepository
{
    private ?MongoCollection $collection = null;

    public function __construct(private AuditRecordProjection $projection, private IdentifierRepository $identifiers)
    {
    }

    public function store(object $record): void
    {
        $document = $this->projection->document($record);
        try {
            $result = $this->collection()->insertOne($document);
            if (!$result->isAcknowledged()) {
                throw new RuntimeException('La entrega de auditoría no fue confirmada.');
            }
        } catch (BulkWriteException $exception) {
            if ($exception->getCode() !== 11000) {
                throw $exception;
            }
            $existing = $this->collection()->findOne(['_id' => $document['_id']], ['projection' => ['payload_hash' => 1]]);
            if ($existing === null || !hash_equals($document['payload_hash'], (string) ($existing['payload_hash'] ?? ''))) {
                throw new RuntimeException('La identidad del registro de auditoría ya existe con otro contenido.');
            }
        }
    }

    public function paginate(AuditScope $scope, array $filters): LengthAwarePaginator
    {
        $match = $this->scope($scope);
        $match['occurred_at_iso'] = [
            '$gte' => \App\Support\Time\BogotaDateTime::startOfDayUtc($filters['date_from'])->format('Y-m-d\TH:i:s.u\Z'),
        ];
        // El año 10000 no mantiene el orden lexicográfico de las fechas ISO de cuatro dígitos.
        if ($filters['date_to'] !== '9999-12-31') {
            $match['occurred_at_iso']['$lt'] = \App\Support\Time\BogotaDateTime::afterDayUtc($filters['date_to'])->format('Y-m-d\TH:i:s.u\Z');
        }
        foreach (['organization_id', 'event', 'resource_type', 'resource_id'] as $key) {
            if (isset($filters[$key])) {
                // Defensa adicional: ningún filtro puede reemplazar la institución del alcance autorizado.
                if ($key === 'organization_id' && $scope->kind === 'department') {
                    $match['$and'][] = ['organization_id' => $filters[$key]];
                } elseif ($key !== 'organization_id' || $scope->kind === 'national') {
                    $match[$key] = $filters[$key];
                }
            }
        }
        if (isset($filters['actor'])) {
            $ids = DB::table('users')->whereRaw("name ILIKE ? ESCAPE '!'", ['%'.strtr($filters['actor'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%'])->pluck('id');
            $match['actor_user_id'] = ['$in' => $ids->map(fn ($id): string => (string) $id)->all()];
        }
        if (isset($filters['user_id'])) {
            $match['$and'][] = ['actor_user_id' => (string) $filters['user_id']];
        }
        if (isset($filters['module'])) {
            $prefixes = $filters['module'] === 'other' ? AuditCatalog::knownPrefixes() : AuditCatalog::prefixes($filters['module']);
            $expression = ['$in' => [['$arrayElemAt' => [['$split' => ['$event', '.']], 0]], $prefixes]];
            $match['$and'][] = ['$expr' => $filters['module'] === 'other' ? ['$not' => [$expression]] : $expression];
        }
        $page = (int) ($filters['page'] ?? 1);
        $pipeline = $this->canonicalPipeline($match);
        $pipeline[] = ['$facet' => [
            'count' => [['$count' => 'total']],
            'entries' => [['$sort' => ['occurred_at_iso' => -1, 'source' => 1, 'id' => -1]],
                ['$skip' => ($page - 1) * 25], ['$limit' => 25], ['$unset' => '_superseding']],
        ]];
        $result = $this->aggregate($pipeline)[0];

        return new LengthAwarePaginator($this->hydrate($result['entries']), $result['count'][0]['total'] ?? 0, 25, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page',
        ]);
    }

    public function find(AuditScope $scope, string $source, string $id): ?object
    {
        $pipeline = $this->canonicalPipeline($this->scope($scope) + ['_id' => $source.':'.$id]);
        $pipeline[] = ['$limit' => 1];

        return $this->hydrate($this->aggregate($pipeline))->first();
    }

    public function totalCount(): int
    {
        $pipeline = $this->canonicalPipeline([]);
        $pipeline[] = ['$count' => 'total'];

        return (int) ($this->aggregate($pipeline)[0]['total'] ?? 0);
    }

    private function scope(AuditScope $scope): array
    {
        if ($scope->kind === 'organization' && $scope->organizationId !== null) {
            return ['organization_id' => $this->identifiers->external('organizations', $scope->organizationId)];
        }
        if ($scope->kind === 'department') {
            $this->identifiers->prime('organizations', $scope->organizationIds);

            return ['organization_id' => ['$in' => array_map(
                fn (string $id): string => $this->identifiers->external('organizations', $id),
                $scope->organizationIds,
            )]];
        }
        if ($scope->kind !== 'national') {
            throw new RuntimeException('La consulta de auditoría necesita un alcance válido.');
        }

        return [];
    }

    /** La referencia explícita a una revisión prevalece incluso si se entregó después de ella. */
    private function canonicalPipeline(array $match): array
    {
        $legacy = [
            ['$match' => ['$and' => [$match === [] ? (object) [] : $match, ['source' => ['$ne' => 'events']]]]],
            ['$lookup' => [
                'from' => config('audit.mongodb.collection'),
                'let' => ['source' => '$source', 'revision' => '$id', 'organization' => '$organization_id',
                    'type' => '$resource_type', 'resource' => '$resource_id', 'event' => '$event'],
                'pipeline' => [['$match' => ['source' => 'events', 'before_values' => ['$ne' => null],
                    '$expr' => ['$and' => [
                        ['$ne' => ['$$source', 'events']], ['$ne' => ['$$organization', null]],
                        ['$eq' => ['$organization_id', '$$organization']], ['$eq' => ['$resource_type', '$$type']],
                        ['$eq' => ['$resource_id', '$$resource']], ['$eq' => ['$event', '$$event']],
                        ['$eq' => ['$metadata.revision_id', '$$revision']],
                    ]]]], ['$limit' => 1], ['$project' => ['_id' => 1]]],
                'as' => '_superseding',
            ]],
            ['$match' => ['_superseding' => ['$eq' => []]]],
        ];

        // Los eventos modernos no necesitan buscar revisiones; sólo el flujo histórico hace el join.
        return [
            ['$match' => ['$and' => [$match === [] ? (object) [] : $match, ['source' => 'events']]]],
            ['$unionWith' => ['coll' => config('audit.mongodb.collection'), 'pipeline' => $legacy]],
        ];
    }

    private function aggregate(array $pipeline): array
    {
        return $this->collection()->aggregate($pipeline, ['maxTimeMS' => config('audit.mongodb.query_timeout_ms', 3000)])->toArray();
    }

    private function hydrate(array $documents): Collection
    {
        $rows = collect($documents);
        $actors = DB::table('users')->whereIn('id', $rows->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');
        $organizations = DB::table('organizations')->whereIn('external_id', $rows->pluck('organization_id')->filter()->unique())->pluck('name', 'external_id');

        return $rows->map(function (array $document) use ($actors, $organizations): object {
            $document['occurred_at'] = $document['occurred_at_iso'];
            $document['actor_name'] = $actors[$document['actor_user_id']] ?? null;
            $document['organization_name'] = $organizations[$document['organization_id']] ?? null;
            unset($document['_id'], $document['_superseding'], $document['payload_hash']);

            return (object) $document;
        });
    }

    private function collection(): MongoCollection
    {
        if ($this->collection === null) {
            $database = (string) config('audit.mongodb.database');
            $client = new Client((string) config('audit.mongodb.uri'), [
                'username' => config('audit.mongodb.username'), 'password' => config('audit.mongodb.password'),
                'authSource' => config('audit.mongodb.auth_source', $database),
                'serverSelectionTimeoutMS' => config('audit.mongodb.timeout_ms', 1000),
                'connectTimeoutMS' => config('audit.mongodb.timeout_ms', 1000),
                'socketTimeoutMS' => config('audit.mongodb.query_timeout_ms', 3000),
                'appname' => 'SYSFOREST-audit', 'w' => 'majority', 'journal' => true,
            ], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
            $this->collection = $client->selectCollection($database, (string) config('audit.mongodb.collection'));
        }

        return $this->collection;
    }
}
