<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Services\Audit\AuditAccessService;
use App\Services\Audit\AuditFilterService;
use Illuminate\Foundation\Http\FormRequest;

class AuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (app(AuditAccessService::class)->canView($this->user())
                || app(AuditAccessService::class)->canViewNational($this->user()));
    }

    public function rules(): array
    {
        return [];
    }

    /** El Service también valida cuando la consulta no procede del formulario HTTP. */
    public function filters(): array
    {
        return app(AuditFilterService::class)->validate($this->query());
    }
}
