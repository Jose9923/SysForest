<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const COLUMNS = [
        'users' => ['email_verified_at', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'failed_jobs' => ['failed_at'],
    ];

    public function up(): void
    {
        $this->convert('timestamp without time zone', 'with time zone');
    }

    public function down(): void
    {
        $this->convert('timestamp with time zone', 'without time zone');
    }

    private function convert(string $expectedType, string $targetZone): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $definition = DB::selectOne(
                    "SELECT data_type, datetime_precision FROM information_schema.columns
                     WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
                    [$table, $column],
                );
                if ($definition === null || $definition->data_type !== $expectedType) {
                    throw new LogicException('La conversión horaria requiere el tipo original esperado en '.$table.'.'.$column.'.');
                }
                $precision = (int) $definition->datetime_precision;
                if ($precision < 0 || $precision > 6) {
                    throw new LogicException('La precisión horaria de '.$table.'.'.$column.' no es compatible.');
                }

                // Los identificadores son constantes. Los valores legacy ya representan UTC;
                // AT TIME ZONE preserva el instante y no modifica nulabilidad ni defaults.
                DB::statement('ALTER TABLE "'.$table.'" ALTER COLUMN "'.$column.'" TYPE timestamp('.$precision.') '
                    .$targetZone.' USING "'.$column.'" AT TIME ZONE \'UTC\'');
            }
        }
    }
};
