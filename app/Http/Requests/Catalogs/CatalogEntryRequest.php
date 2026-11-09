<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogs;

use App\Catalogs\CatalogInput;
use Illuminate\Foundation\Http\FormRequest;

class CatalogEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogs.manage') ?? false;
    }

    public function rules(): array
    {
        return CatalogInput::rules($this->route('entry') !== null, (string) $this->route('catalog'));
    }
}
