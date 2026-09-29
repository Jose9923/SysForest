<?php

declare(strict_types=1);

namespace App\Imports;

use App\Reports\InventoryExportSchema;

/** Conserva las 94 variables del instrumento; las descompuestas comparten su celda de origen. */
final class ImportTemplateSchema
{
    public const VERSION = 'SYSFOREST_IMPORT_V1';

    /** @return array<string, array{label:string,group:string,mode:string,type:string,required:bool,description:string,source:?string}> */
    public function columns(): array
    {
        $columns = [];
        $add = static function (string $key, string $label, string $group, string $type = 'text', bool $required = false, string $description = '', ?string $source = null, string $mode = 'input') use (&$columns): void {
            $columns[$key] = compact('label', 'group', 'mode', 'type', 'required', 'description', 'source');
        };
        $add('code', 'Código SYSFOREST', 'Identidad', description: 'Opcional. Vacío genera código; un código indicado debe usar el prefijo vigente de la organización y al menos seis dígitos, sin ceros adicionales. Una colisión impide todo el lote.');
        $add('external_number', 'N. ÁRBOL / número legado', 'Identidad', description: 'Hasta 100 caracteres; conserva ceros iniciales. No se usa como identidad inequívoca.', source: 'Hoja1!A4');
        $add('placement_code', 'Emplazamiento', 'Ubicación', 'catalog', description: 'Código exacto de placement_code en Catalogos.', source: 'Hoja1!B4');
        $add('coordinate_raw', 'Georreferenciación (coordenadas)', 'Ubicación', description: 'Latitud, longitud en grados decimales o DMS. Ejemplo: 4.154, -73.633. No se infiere el datum.', source: 'Hoja1!C6');
        $add('species_code', 'Código de especie', 'Taxonomía', 'catalog', description: 'Código exacto de species_code. Resuelve nombre científico, nombres comunes y familia.');
        foreach (['common_name' => ['Nombre común', 'D6'], 'scientific_name' => ['Nombre científico', 'E6'], 'family' => ['Familia', 'F6']] as $key => [$label, $source]) {
            $add($key, $label, 'Taxonomía', description: 'Dejar vacío: se obtiene de la especie seleccionada; no se importan nombres libres.', source: 'Hoja1!'.$source, mode: 'backend');
        }
        $add('growth_habit_code', 'Hábito de crecimiento', 'Taxonomía', 'catalog', description: 'Código exacto de growth_habit_code.', source: 'Hoja1!G6');
        $add('establishment_origin_code', 'Origen de establecimiento', 'Taxonomía', 'catalog', description: 'Código exacto de establishment_origin_code; no equivale al origen biogeográfico.', source: 'Hoja1!H4');
        $add('biogeographic_origin_code', 'Origen biogeográfico', 'Taxonomía', 'catalog', description: 'Código exacto de biogeographic_origin_code; independiente del establecimiento.', source: 'Hoja1!H4');
        $add('current_status', 'Estado actual', 'Identidad', 'catalog', true, 'Código exacto de current_status en Catalogos.');
        $add('is_protected', 'Protegido', 'Identidad', 'boolean', description: '0 o 1. Vacío equivale a 0.');
        $add('is_heritage', 'Patrimonial', 'Identidad', 'boolean', description: '0 o 1. Vacío equivale a 0.');
        $add('inspection_date', 'Fecha de inspección', 'Inspección', 'date', true, 'Texto AAAA-MM-DD; no fecha serial de Excel.');
        foreach ([
            'cap_cm' => ['CAP (cm)', 'I6', 'decimal'],
            'dap_m' => ['DAP (m)', 'J6', 'backend'],
            'radius_m' => ['Radio (m)', 'K6', 'backend'],
            'basal_area_m2' => ['Área basal (m²)', 'L6', 'backend'],
            'number_of_stems' => ['Número de fustes o estípites', 'M6', 'integer'],
            'form_factor' => ['Factor de forma', 'N6', 'decimal'],
            'total_height_m' => ['Altura total (m)', 'O6', 'decimal'],
            'commercial_height_m' => ['Altura comercial (m)', 'P6', 'decimal'],
            'commercial_volume_m3' => ['Volumen comercial (m³)', 'Q6', 'backend'],
            'total_volume_m3' => ['Volumen total (m³)', 'R6', 'backend'],
            'crown_x_m' => ['Copa X (m)', 'S6', 'decimal'],
            'crown_y_m' => ['Copa Y (m)', 'T6', 'decimal'],
            'diameter_class_number' => ['Clase diamétrica', 'U6', 'backend'],
            'height_class_number' => ['Clase de altura', 'V6', 'backend'],
            'maturity_state' => ['Estado de madurez', 'W6', 'backend'],
        ] as $key => [$label, $source, $type]) {
            $backend = $type === 'backend';
            $description = $backend ? 'Dejar vacío: SYSFOREST recalcula con las reglas forestales vigentes; no acepta valores ni fórmulas.'
                : ($type === 'integer' ? 'Entero positivo como texto; máximo BIGINT. No notación científica.' : 'Texto con punto decimal, sin miles ni notación científica; hasta seis decimales.');
            if ($key === 'form_factor') {
                $description .= ' Entre 0.45 y 0.70, incluidos.';
            }
            if ($key === 'commercial_height_m') {
                $description .= ' No puede superar la altura total.';
            }
            $resultType = match ($key) {
                'maturity_state' => 'text',
                'diameter_class_number', 'height_class_number' => 'integer',
                default => $backend ? 'decimal' : $type,
            };
            $add($key, $label, 'Dasometría', $resultType, description: $description, source: 'Hoja1!'.$source, mode: $backend ? 'backend' : 'input');
        }
        foreach (InventoryExportSchema::ORIGINAL_CONDITIONS as $code => $definition) {
            $add('condition_'.$code, trim($definition['label']), 'Condiciones', 'boolean', description: '1 selecciona; 0 o vacío no selecciona. Un blanco no acredita ausencia examinada.', source: $definition['source']);
        }
        foreach (['flowering' => ['Floración', 'CE6'], 'fruiting' => ['Fructificación', 'CF6']] as $key => [$label, $source]) {
            $add($key.'_date', $label.' / fecha', 'Fenología', 'date', description: 'Texto AAAA-MM-DD; vacío si no se conoce.', source: 'Hoja1!'.$source);
            $add($key.'_development', $label.' / desarrollo', 'Fenología', description: 'Descripción observada; no se inventa un catálogo de estados.', source: 'Hoja1!'.$source);
        }
        $add('fauna_category_codes', 'Fauna asociada', 'Fauna', 'catalog_list', description: 'Códigos exactos de fauna_category_codes separados por |, sin repetir.', source: 'Hoja1!CM6');
        $add('fauna_observations', 'Observaciones de fauna', 'Fauna', description: 'Texto observado; hasta 20.000 caracteres.', source: 'Hoja1!CM6');
        $add('fauna_photo_urls', 'Foto fauna', 'Evidencias', description: 'Dejar vacío. Las fotografías se cargan por el módulo de evidencias después de importar; no se descargan URLs.', source: 'Hoja1!CN4', mode: 'evidence');
        $add('tree_photo_urls', 'Foto individuo', 'Evidencias', description: 'Dejar vacío. No se admiten fotografías incrustadas ni enlaces para su carga.', source: 'Hoja1!CO4', mode: 'evidence');
        $add('general_observations', 'Observaciones de inspección', 'Observaciones', description: 'Texto; hasta 20.000 caracteres.', source: 'Hoja1!CP4');
        $add('location_description', 'Descripción de ubicación', 'Ubicación', description: 'Texto; hasta 2.000 caracteres.');
        $add('datum', 'Datum declarado', 'Ubicación', description: 'Declarar WGS84 cuando corresponda; no se transforma otro datum automáticamente.');
        $add('accuracy_m', 'Exactitud declarada (m)', 'Ubicación', 'decimal', description: 'Texto numérico no negativo; no se infiere.');
        $add('gps_brand', 'Marca GPS', 'Ubicación');
        $add('gps_model', 'Modelo GPS', 'Ubicación');
        $add('captured_at', 'Fecha de captura de ubicación', 'Ubicación', 'datetime', description: 'Texto ISO 8601 con zona, por ejemplo 2026-01-31T10:30:00-05:00.');

        return $columns;
    }

    /** El orden y los identificadores forman parte del contrato; las etiquetas se explican en Diccionario. */
    public function headers(): array
    {
        return array_keys($this->columns());
    }
}
