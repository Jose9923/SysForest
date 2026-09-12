<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Catalogs\ClassificationMetadataInput;
use Illuminate\Foundation\Http\FormRequest;

class ClassificationMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalogs.manage') ?? false;
    }

    public function rules(): array
    {
        return ClassificationMetadataInput::rules();
    }
}
