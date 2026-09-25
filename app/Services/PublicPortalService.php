<?php

declare(strict_types=1);

namespace App\Services;

/** Prepara exclusivamente datos públicos y metadatos básicos para el layout ciudadano. */
class PublicPortalService
{
    public function __construct(private PublicTreeService $trees, private PublicMapService $map)
    {
    }

    public function home(): array
    {
        return ['indicators' => $this->trees->indicators([]),
            'mapUrl' => route('public.map', absolute: false), 'listUrl' => route('public.trees.index', absolute: false),
            'seo' => $this->seo('Inventario forestal urbano', 'Consulta el arbolado publicado por las organizaciones participantes en SYSFOREST.', '/portal')];
    }

    public function map(array $input): array
    {
        return $this->map->page($input) + [
            'seo' => $this->seo('Mapa público SIGAU', 'Explora el inventario forestal publicado, su distribución y concentración espacial.', '/portal/map'),
            'listUrl' => route('public.trees.index', $input, false),
        ];
    }

    public function listing(array $input): array
    {
        return $this->trees->listing($input) + [
            'seo' => $this->seo('Consulta ciudadana de árboles', 'Busca árboles publicados por territorio, especie, estado e inspección forestal.', '/portal/trees'),
        ];
    }

    public function detail(string $organizationCode, string $treeCode, array $input): array
    {
        $data = $this->trees->detail($organizationCode, $treeCode, $input);

        return $data + ['seo' => $this->seo(
            'Árbol '.$data['tree']['code'],
            'Ficha forestal pública e historial publicado del árbol '.$data['tree']['code'].'.',
            $data['tree']['url']
        )];
    }

    private function seo(string $title, string $description, string $path): array
    {
        return ['title' => $title.' · SYSFOREST', 'description' => $description,
            'canonical' => rtrim((string) config('app.url'), '/').$path];
    }
}
