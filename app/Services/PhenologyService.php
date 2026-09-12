<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Validator;

/** CE y CF contienen fecha y desarrollo independientes, sin catálogo de estados respaldado. */
class PhenologyService
{
    public function normalize(array $input): array
    {
        $rules = [];
        foreach (['flowering', 'fruiting'] as $stage) {
            $rules[$stage.'_date'] = ['nullable', 'date_format:Y-m-d'];
            $rules[$stage.'_development'] = ['nullable', 'string', 'max:20000'];
        }
        $data = Validator::make($input, $rules, \App\Validation\TreeInspectionInput::messages())->validate();

        return array_replace(array_fill_keys(array_keys($rules), null), $data);
    }
}
