<?php

declare(strict_types=1);

namespace App\Reports;

/** Esquema estable: las 65 columnas originales se identifican por código y órgano, nunca por etiquetas ambiguas. */
class InventoryExportSchema
{
    private array $cache = [];

    public const ORIGINAL_CONDITIONS = [
        'PHY_ROOT_BAD_ANCHORAGE' => ['label' => 'Física / Sistema radicular / Anclaje malo', 'source' => 'Hoja1!X6'],
        'PHY_ROOT_GOOD_ANCHORAGE' => ['label' => 'Física / Sistema radicular / Anclaje bueno', 'source' => 'Hoja1!Y6'],
        'PHY_ROOT_BUTTRESSES' => ['label' => 'Física / Sistema radicular / Aletones', 'source' => 'Hoja1!Z6'],
        'PHY_ROOT_INSUFFICIENT_CONFINEMENT' => ['label' => 'Física / Sistema radicular / Confinamiento insuficiente', 'source' => 'Hoja1!AA6'],
        'PHY_STEM_STRAIGHT' => ['label' => 'Física / Fuste o estípite / Recto', 'source' => 'Hoja1!AB6'],
        'PHY_STEM_CROOKED' => ['label' => 'Física / Fuste o estípite / Torcido', 'source' => 'Hoja1!AC6'],
        'PHY_STEM_TWISTED' => ['label' => 'Física / Fuste o estípite / Retorcido', 'source' => 'Hoja1!AD6'],
        'PHY_STEM_LOW_BRANCHING' => ['label' => 'Física / Fuste o estípite / Ramificación abajo', 'source' => 'Hoja1!AE6'],
        'PHY_STEM_LEAN_UNDER_30' => ['label' => 'Física / Fuste o estípite / Inclinado < de 30 grados ', 'source' => 'Hoja1!AF6'],
        'PHY_STEM_LEAN_OVER_30' => ['label' => 'Física / Fuste o estípite / Inclinado > de 30 grados ', 'source' => 'Hoja1!AG6'],
        'PHY_STEM_VERTICAL_CRACKS' => ['label' => 'Física / Fuste o estípite / Grietas o fisuras  verticales', 'source' => 'Hoja1!AH6'],
        'PHY_STEM_HORIZONTAL_CRACKS' => ['label' => 'Física / Fuste o estípite / Grietas o fisuras horizontales', 'source' => 'Hoja1!AI6'],
        'PHY_STEM_PRICKLES' => ['label' => 'Física / Fuste o estípite / Aguijones', 'source' => 'Hoja1!AJ6'],
        'PHY_STEM_THORNS' => ['label' => 'Física / Fuste o estípite / Espinas', 'source' => 'Hoja1!AK6'],
        'PHY_STEM_IMPACT_MARKS' => ['label' => 'Física / Fuste o estípite / Marcas por impacto', 'source' => 'Hoja1!AL6'],
        'PHY_BRANCH_MORE_THAN_ONE_PER_NODE' => ['label' => 'Física / Ramas / Mas de una por nudo', 'source' => 'Hoja1!AM6'],
        'PHY_BRANCH_HANGING' => ['label' => 'Física / Ramas / Colgantes', 'source' => 'Hoja1!AN6'],
        'PHY_BRANCH_DEAD' => ['label' => 'Física / Ramas / Muertas', 'source' => 'Hoja1!AO6'],
        'PHY_BRANCH_SUCKER_SHOOTS' => ['label' => 'Física / Ramas / Chupones', 'source' => 'Hoja1!AP6'],
        'PHY_BRANCH_POORLY_PRUNED' => ['label' => 'Física / Ramas / Mal podadas', 'source' => 'Hoja1!AQ6'],
        'PHY_CROWN_ALTERED' => ['label' => 'Física / Copa / Alterada', 'source' => 'Hoja1!AR6'],
        'PHY_CROWN_UNALTERED' => ['label' => 'Física / Copa / Sin alteración', 'source' => 'Hoja1!AS6'],
        'PHY_CROWN_UNBALANCED_BY_PRUNING' => ['label' => 'Física / Copa / Desestabilizada por malas podas', 'source' => 'Hoja1!AT6'],
        'PHT_ROOT_VISIBLE_ROOTS' => ['label' => 'Fitosanitaria / Sistema radicular / Raices visibles', 'source' => 'Hoja1!AU6'],
        'PHT_ROOT_TWISTED_ROOTS' => ['label' => 'Fitosanitaria / Sistema radicular / Raices retorcidas', 'source' => 'Hoja1!AV6'],
        'PHT_ROOT_PRUNED_BY_WORKS' => ['label' => 'Fitosanitaria / Sistema radicular / Podas por acción de obras', 'source' => 'Hoja1!AW6'],
        'PHT_STEM_HEALTHY' => ['label' => 'Fitosanitaria / Fuste o estípite / Sano', 'source' => 'Hoja1!AX6'],
        'PHT_STEM_GALLS_OR_TUMORS' => ['label' => 'Fitosanitaria / Fuste o estípite / Agallas o tumores', 'source' => 'Hoja1!AY6'],
        'PHT_STEM_FUNGI' => ['label' => 'Fitosanitaria / Fuste o estípite / Hongos', 'source' => 'Hoja1!AZ6'],
        'PHT_STEM_ROT' => ['label' => 'Fitosanitaria / Fuste o estípite / Pudrición', 'source' => 'Hoja1!BA6'],
        'PHT_STEM_NECROSIS' => ['label' => 'Fitosanitaria / Fuste o estípite / Necrosis', 'source' => 'Hoja1!BB6'],
        'PHT_STEM_CAVITY_UNDER_30' => ['label' => 'Fitosanitaria / Fuste o estípite / Oquedad media < 30%', 'source' => 'Hoja1!BC6'],
        'PHT_STEM_CAVITY_OVER_30' => ['label' => 'Fitosanitaria / Fuste o estípite / Oquedad grande > 30%', 'source' => 'Hoja1!BD6'],
        'PHT_STEM_GIRDLING' => ['label' => 'Fitosanitaria / Fuste o estípite / Anillamiento', 'source' => 'Hoja1!BE6'],
        'PHT_STEM_CONSTRICTING_ELEMENTS' => ['label' => 'Fitosanitaria / Fuste o estípite / Elementos de estrangulamiento', 'source' => 'Hoja1!BF6'],
        'PHT_STEM_PARASITIC_HEMIPARASITIC_EPIPHYTES' => ['label' => 'Fitosanitaria / Fuste o estípite / Epífitas parásitas o hemiparasitas', 'source' => 'Hoja1!BG6'],
        'PHT_STEM_VINE' => ['label' => 'Fitosanitaria / Fuste o estípite / Bejuco o liana', 'source' => 'Hoja1!BH6'],
        'PHT_STEM_STRANGLER' => ['label' => 'Fitosanitaria / Fuste o estípite / Matapalo', 'source' => 'Hoja1!BI6'],
        'PHT_STEM_TERMITES' => ['label' => 'Fitosanitaria / Fuste o estípite / Termitas o comején', 'source' => 'Hoja1!BJ6'],
        'PHT_STEM_INSECT_HOST' => ['label' => 'Fitosanitaria / Fuste o estípite / Hospedero insectos', 'source' => 'Hoja1!BK6'],
        'PHT_BRANCH_ROT' => ['label' => 'Fitosanitaria / Ramas / Podridas', 'source' => 'Hoja1!BL6'],
        'PHT_BRANCH_FUNGI' => ['label' => 'Fitosanitaria / Ramas / Hongos', 'source' => 'Hoja1!BM6'],
        'PHT_BRANCH_PARASITIC_HEMIPARASITIC_EPIPHYTES' => ['label' => 'Fitosanitaria / Ramas / Epífitas parásitas o hemiparasitas', 'source' => 'Hoja1!BN6'],
        'PHT_BRANCH_DEAD' => ['label' => 'Fitosanitaria / Ramas / Muertas', 'source' => 'Hoja1!BO6'],
        'PHT_FOLIAGE_SCANT' => ['label' => 'Fitosanitaria / Follaje / Escaso', 'source' => 'Hoja1!BP6'],
        'PHT_FOLIAGE_ABUNDANT' => ['label' => 'Fitosanitaria / Follaje / Abundante', 'source' => 'Hoja1!BQ6'],
        'PHT_FOLIAGE_HEALTHY' => ['label' => 'Fitosanitaria / Follaje / Sano', 'source' => 'Hoja1!BR6'],
        'PHT_FOLIAGE_CHLOROSIS' => ['label' => 'Fitosanitaria / Follaje / Clorosis', 'source' => 'Hoja1!BS6'],
        'PHT_FOLIAGE_CURLING' => ['label' => 'Fitosanitaria / Follaje / Entorchamiento', 'source' => 'Hoja1!BT6'],
        'PHT_FOLIAGE_WILTING' => ['label' => 'Fitosanitaria / Follaje / Marchitamiento', 'source' => 'Hoja1!BU6'],
        'PHT_FOLIAGE_LOCALIZED_ROT' => ['label' => 'Fitosanitaria / Follaje / Pudrición localizada', 'source' => 'Hoja1!BV6'],
        'PHT_FOLIAGE_PARASITIC_HEMIPARASITIC_EPIPHYTES' => ['label' => 'Fitosanitaria / Follaje / Epífitas parásitas o hemiparasitas', 'source' => 'Hoja1!BW6'],
        'PHT_FOLIAGE_ABNORMAL_LEAF_ABSCISSION' => ['label' => 'Fitosanitaria / Follaje / Absición foliar anormal - No desfoliación genetica', 'source' => 'Hoja1!BX6'],
        'PHT_FLOWER_FRUIT_SCANT' => ['label' => 'Fitosanitaria / Flores y frutos / Escasa', 'source' => 'Hoja1!BY6'],
        'PHT_FLOWER_FRUIT_ABUNDANT' => ['label' => 'Fitosanitaria / Flores y frutos / Abundante', 'source' => 'Hoja1!BZ6'],
        'PHT_FLOWER_FRUIT_HEALTHY' => ['label' => 'Fitosanitaria / Flores y frutos / Sana', 'source' => 'Hoja1!CA6'],
        'PHT_FLOWER_FRUIT_LOCAL_OR_GENERAL_ROT' => ['label' => 'Fitosanitaria / Flores y frutos / Pudrición localizada o general', 'source' => 'Hoja1!CB6'],
        'PHT_FLOWER_FRUIT_WILTING_BEFORE_POLLINATION_DURING_FRUITING' => ['label' => 'Fitosanitaria / Flores y frutos / Marchitamiento antes de polinización - En fructificación', 'source' => 'Hoja1!CC6'],
        'PHT_FLOWER_FRUIT_ABNORMAL_FLOWER_FRUIT_ABSCISSION' => ['label' => 'Fitosanitaria / Flores y frutos / Absición anormal de flores y/o frutos', 'source' => 'Hoja1!CD6'],
        'PHT_GENERAL_HEALTHY' => ['label' => 'Fitosanitaria / Estado fitosanitario general del árbol / Sano', 'source' => 'Hoja1!CG6'],
        'PHT_GENERAL_DIEBACK' => ['label' => 'Fitosanitaria / Estado fitosanitario general del árbol / Muerte descendente', 'source' => 'Hoja1!CH6'],
        'PHT_GENERAL_DYING_OVER_50' => ['label' => 'Fitosanitaria / Estado fitosanitario general del árbol / Proceso de muerte > 50%', 'source' => 'Hoja1!CI6'],
        'PHT_GENERAL_DYING_UNDER_50' => ['label' => 'Fitosanitaria / Estado fitosanitario general del árbol / Proceso de muerte < 50%', 'source' => 'Hoja1!CJ6'],
        'PHT_GENERAL_BENIGN_INSECT_HOST' => ['label' => 'Fitosanitaria / Estado fitosanitario general del árbol / Hospedero insectos benignos - Avispas, abejas', 'source' => 'Hoja1!CK6'],
        'PHT_GENERAL_HARMFUL_INSECT_HOST' => ['label' => 'Fitosanitaria / Estado fitosanitario general del árbol / Hospedero insectos dañinos - Trozadores, perforadores', 'source' => 'Hoja1!CL6'],
    ];

    /** @return list<array{key: string, label: string, type: string, group: string, unit?: string, source?: string}> */
    public function columns(bool $public): array
    {
        if (isset($this->cache[(int) $public])) {
            return $this->cache[(int) $public];
        }
        $columns = [];
        $add = static function (string $key, string $label, string $group, string $type = 'text', ?string $unit = null, ?string $source = null) use (&$columns): void {
            $column = compact('key', 'label', 'type', 'group');
            if ($unit !== null) {
                $column['unit'] = $unit;
            }
            if ($source !== null) {
                $column['source'] = $source;
            }
            $columns[] = $column;
        };

        $add('code', 'Código SYSFOREST', 'identidad');
        $add('organization_code', $public ? 'Código público de organización' : 'Código de organización', 'identidad');
        $add('organization', 'Organización', 'identidad');
        foreach (['country' => 'País', 'department' => 'Departamento', 'municipality' => 'Municipio'] as $key => $label) {
            $add($key.'_code', 'Código de '.$label, 'ubicación');
            $add($key, $label, 'ubicación');
        }
        if (!$public) {
            $add('external_number', 'N. ÁRBOL / número legado', 'identidad', source: 'Hoja1!A4');
        }
        $add('placement', 'Emplazamiento', 'ubicación', source: 'Hoja1!B4');
        $add('latitude', 'Latitud', 'ubicación', 'decimal', '°', 'Hoja1!C6');
        $add('longitude', 'Longitud', 'ubicación', 'decimal', '°', 'Hoja1!C6');
        if (!$public) {
            $add('coordinate_raw', 'Coordenada original', 'ubicación', source: 'Hoja1!C6');
            $add('coordinate_format', 'Formato de coordenada', 'ubicación');
            $add('datum', 'Datum declarado', 'ubicación');
            $add('accuracy_m', 'Exactitud declarada', 'ubicación', 'decimal', 'm');
            $add('gps_brand', 'Marca del GPS', 'ubicación');
            $add('gps_model', 'Modelo del GPS', 'ubicación');
            $add('location_captured_at', 'Fecha de captura de ubicación (UTC)', 'ubicación');
            $add('location_description', 'Descripción de ubicación', 'ubicación');
        }
        $add('common_name', 'Nombre común principal', 'taxonomía', source: 'Hoja1!D6');
        $add('common_names', 'Nombres comunes', 'taxonomía', source: 'Hoja1!D6');
        $add('scientific_name', 'Nombre científico', 'taxonomía', source: 'Hoja1!E6');
        $add('family', 'Familia', 'taxonomía', source: 'Hoja1!F6');
        $add('growth_habit', 'Hábito de crecimiento', 'taxonomía', source: 'Hoja1!G6');
        $add('establishment_origin', 'Origen de establecimiento', 'taxonomía', source: 'Hoja1!H4');
        $add('biogeographic_origin', 'Origen biogeográfico', 'taxonomía', source: 'Hoja1!H4');
        $add('current_status', 'Estado actual (código)', 'identidad');
        $add('current_status_label', 'Estado actual', 'identidad');
        $add('is_protected', 'Protegido', 'identidad', 'boolean');
        $add('is_heritage', 'Patrimonial', 'identidad', 'boolean');
        $add('latest_inspection_date', 'Fecha de la inspección seleccionada', 'dasometría');
        foreach ([
            'cap_cm' => ['CAP', 'cm', 'I6'], 'dap_m' => ['DAP', 'm', 'J6'],
            'radius_m' => ['Radio', 'm', 'K6'], 'basal_area_m2' => ['Área basal', 'm²', 'L6'],
            'number_of_stems' => ['Número de fustes o estípites', null, 'M6'],
            'form_factor' => ['Factor de forma', null, 'N6'], 'total_height_m' => ['Altura total', 'm', 'O6'],
            'commercial_height_m' => ['Altura comercial', 'm', 'P6'],
            'commercial_volume_m3' => ['Volumen comercial', 'm³', 'Q6'], 'total_volume_m3' => ['Volumen total', 'm³', 'R6'],
            'crown_x_m' => ['Copa X', 'm', 'S6'], 'crown_y_m' => ['Copa Y', 'm', 'T6'],
            'diameter_class_number' => ['Clase diamétrica', null, 'U6'], 'height_class_number' => ['Clase de altura', null, 'V6'],
        ] as $key => [$label, $unit, $source]) {
            $integer = in_array($key, ['number_of_stems', 'diameter_class_number', 'height_class_number'], true);
            $add($key, $label, 'dasometría', $integer ? 'integer' : 'decimal', $unit, 'Hoja1!'.$source);
        }
        $add('maturity_state', 'Estado de madurez', 'dasometría', source: 'Hoja1!W6');
        if (!$public) {
            $add('classification_rule_version', 'Versión de clasificación', 'dasometría');
            $add('calculation_rule_version', 'Versión de cálculo', 'dasometría');
        }
        foreach (self::ORIGINAL_CONDITIONS as $code => $definition) {
            $add('condition_'.$code, $definition['label'], 'condiciones', source: $definition['source']);
        }
        $add('physical_additional_conditions', 'Condiciones físicas complementarias (código / órgano / nombre)', 'condiciones');
        $add('phytosanitary_additional_conditions', 'Condiciones fitosanitarias complementarias (código / órgano / nombre)', 'condiciones');
        $add('flowering_date', 'Fecha de floración', 'fenología', source: 'Hoja1!CE6');
        $add('fruiting_date', 'Fecha de fructificación', 'fenología', source: 'Hoja1!CF6');
        if (!$public) {
            $add('flowering_development', 'Desarrollo de floración', 'fenología', source: 'Hoja1!CE6');
            $add('fruiting_development', 'Desarrollo de fructificación', 'fenología', source: 'Hoja1!CF6');
        }
        $add('fauna', 'Fauna asociada (categorías)', 'fauna', source: 'Hoja1!CM6');
        if (!$public) {
            $add('fauna_observations', 'Observaciones de fauna', 'fauna', source: 'Hoja1!CM6');
        }
        $add('fauna_photo_urls', 'Fotografías de fauna de la inspección', 'evidencias', source: 'Hoja1!CN4');
        $add('tree_photo_urls', 'Fotografías del individuo de la inspección', 'evidencias', source: 'Hoja1!CO4');
        if (!$public) {
            $add('general_observations', 'Observaciones de inspección', 'observaciones', source: 'Hoja1!CP4');
        }

        return $this->cache[(int) $public] = $columns;
    }

    /** Filtra por lista permitida y completa ausencias sin convertir null, false ni cero. */
    public function project(array $values, bool $public): array
    {
        $row = [];
        foreach ($this->columns($public) as $column) {
            $row[$column['key']] = $values[$column['key']] ?? null;
        }

        return $row;
    }
}
