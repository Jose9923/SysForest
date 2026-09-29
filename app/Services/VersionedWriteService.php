<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRecordException;
use App\Validation\VersionInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/** Se invoca sobre un registro bloqueado dentro de la transacción que lo persistirá. */
class VersionedWriteService
{
    public function advance(Model $record, array $input): void
    {
        $data = Validator::make($input, VersionInput::rules(true))->validate();
        $version = (int) $data['lock_version'];
        if ($version !== $record->lock_version) {
            throw new StaleRecordException();
        }
        $record->setAttribute('lock_version', $version + 1);
    }
}
