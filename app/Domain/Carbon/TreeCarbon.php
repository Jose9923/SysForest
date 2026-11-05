<?php

declare(strict_types=1);

namespace App\Domain\Carbon;

/** Biomasa aérea seca; no representa raíces, suelo, emisiones ni captura anual. */
class TreeCarbon
{
    public const VERSION = 'CHAVE_2014_AGB_IPCC_2006_CF_2';

    public static function method(): array
    {
        return ['version' => self::VERSION, 'formula' => 'AGB = 0.0673 * (rho * D_cm^2 * H_m)^0.976',
            'carbon_fraction' => '0.47', 'co2_ratio' => '44/12',
            'biomass_reference' => 'https://doi.org/10.1111/gcb.12629',
            'carbon_reference' => 'https://www.ipcc-nggip.iges.or.jp/public/2006gl/pdf/4_Volume4/V4_04_Ch4_Forest_Land.pdf#page=48',
            'scope' => 'Estimación preliminar de biomasa aérea de árboles tropicales leñosos de un solo fuste; requiere validación profesional para el contexto local.'];
    }

    public function calculate(array $input, ?array $profile): array
    {
        $status = match (true) {
            empty($input['species_id']) => 'missing_species',
            empty($input['growth_habit_code']) => 'missing_habit',
            $input['growth_habit_code'] !== 'HAB_TREE' => 'unsupported_habit',
            $profile === null => 'missing_profile',
            ($profile['validation_status'] ?? '') === 'pending_density' => 'missing_density_reference',
            ($profile['validation_status'] ?? '') === 'pending_identification' => 'unresolved_identification',
            !$profile['applicable'] => 'not_applicable',
            $input['number_of_stems'] === null => 'missing_stems',
            (int) $input['number_of_stems'] !== 1 => 'multiple_stems',
            $input['dap_m'] === null || $input['total_height_m'] === null => 'missing_measurements',
            (float) $input['dap_m'] * 100 < 5 => 'small_diameter',
            ($profile['validation_status'] ?? '') === 'bibliographic' && (float) $input['total_height_m'] < 1.2 => 'outside_range',
            (float) $input['dap_m'] * 100 > (float) $profile['max_diameter_cm']
                || (float) $input['total_height_m'] > (float) $profile['max_height_m'] => 'outside_range',
            default => 'estimated',
        };
        $result = ['status' => $status, 'biomass_kg' => null, 'carbon_kg' => null, 'co2_kg' => null];
        if ($status !== 'estimated') {
            return $result;
        }
        $diameter = (float) $input['dap_m'] * 100;
        $height = (float) $input['total_height_m'];
        $density = (float) $profile['wood_density'];
        $biomass = 0.0673 * ($density * $diameter ** 2 * $height) ** 0.976;
        $carbon = $biomass * 0.47;
        $co2 = $carbon * 44 / 12;
        if ($density <= 0 || $height <= 0 || !is_finite($co2) || $co2 <= 0 || $co2 >= 1.0e18) {
            return array_replace($result, ['status' => 'outside_range']);
        }

        return ['status' => 'estimated', 'biomass_kg' => number_format($biomass, 6, '.', ''),
            'carbon_kg' => number_format($carbon, 6, '.', ''), 'co2_kg' => number_format($co2, 6, '.', '')];
    }
}
