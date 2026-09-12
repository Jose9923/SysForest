<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Collection;

class CountryRepository
{
    public function upsert(string $code, array $attributes): Country
    {
        return Country::query()->updateOrCreate(['code' => $code], $attributes);
    }

    public function overview(): Collection
    {
        return Country::query()->with(['departments' => fn ($query) => $query->orderBy('name'),
            'departments.municipalities' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get();
    }
}
