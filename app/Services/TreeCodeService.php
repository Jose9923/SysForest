<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Repositories\OrganizationSequenceRepository;
use App\Repositories\TreeRepository;
use Illuminate\Validation\ValidationException;

class TreeCodeService
{
    public function __construct(private OrganizationSequenceRepository $sequences, private TreeRepository $trees)
    {
    }

    /** Seis dígitos es un ancho mínimo; nunca se recicla un código de un registro confirmado o anulado. */
    public function next(Organization $organization): string
    {
        return $organization->tree_code_prefix.'-'.str_pad((string) $this->sequences->nextTreeValue($organization->id), 6, '0', STR_PAD_LEFT);
    }

    /** El código no permite cambiar de prefijo, crear alias con ceros ni recuperar un registro anulado. */
    public function validateImported(Organization $organization, string $code): int
    {
        $prefix = $organization->tree_code_prefix.'-';
        $suffix = str_starts_with($code, $prefix) ? substr($code, strlen($prefix)) : '';
        if (preg_match('/\A[0-9]{6,19}\z/D', $suffix) !== 1
            || bccomp($suffix, '0', 0) <= 0 || bccomp($suffix, (string) (PHP_INT_MAX - 1), 0) > 0
            || $suffix !== str_pad((string) (int) $suffix, 6, '0', STR_PAD_LEFT)) {
            throw ValidationException::withMessages(['code' => 'Usa el prefijo institucional '.$prefix.' y un consecutivo positivo canónico de al menos seis dígitos, o deja el código vacío para asignarlo automáticamente.']);
        }
        if ($this->trees->codeExists($organization->id, $code)) {
            throw ValidationException::withMessages(['code' => 'El código ya pertenece a un individuo de esta organización, incluso si fue anulado. No se actualizan ni reemplazan individuos durante la importación.']);
        }

        return (int) $suffix;
    }

    /** Requiere la transacción del lote; la reserva y los individuos se revierten juntos. */
    public function reserveImported(Organization $organization, array $codes, int $automaticCount = 0): void
    {
        if (count(array_unique($codes)) !== count($codes)) {
            throw ValidationException::withMessages(['code' => 'El archivo repite un código de individuo. Corrige las filas antes de confirmar.']);
        }
        $maximum = 0;
        foreach ($codes as $code) {
            $maximum = max($maximum, $this->validateImported($organization, $code));
        }
        $reserved = $this->sequences->reserveTreeAtLeast($organization->id, $maximum);
        if ($automaticCount < 0 || $reserved > PHP_INT_MAX - $automaticCount) {
            throw ValidationException::withMessages(['code' => 'El consecutivo solicitado no deja capacidad para todos los códigos automáticos de este lote.']);
        }
    }

    /** Punto de entrada exclusivo de creación importada; los formularios siguen prohibiendo code. */
    public function imported(Organization $organization, string $code): string
    {
        $value = $this->validateImported($organization, $code);
        $this->sequences->reserveTreeAtLeast($organization->id, $value);

        return $code;
    }
}
