<?php

declare(strict_types=1);

namespace App\Support;

/** Retira direcciones del libro solo de la presentación; conserva la procedencia persistida. */
final class ExcelReferencePresentation
{
    public static function text(?string $value, bool $includeCapturedObservations = true): ?string
    {
        if ($value === null) {
            return null;
        }
        $block = self::originalCellsBlock($value);
        if ($block !== null) {
            $captured = $includeCapturedObservations ? self::capturedObservations($value) : null;
            $value = str_replace($block, $captured === null ? '' : 'Observaciones de origen: '.$captured, $value);
            $value = preg_replace('/^([A-Z]{1,3}[1-9][0-9]*):[ \t]*/mu', 'Dato de captura: ', $value);
        }
        if (str_contains($value, 'Ornato 2025, revisión autorizada; fila ')) {
            $value = preg_replace('/\b[A-Z]{1,3}[1-9][0-9]*\s*(?=\()/u', '', $value);
            $value = preg_replace('/\bG[1-9][0-9]*:[ \t]*/u', 'Estado observado: ', $value);
        }
        $cell = '\$?[A-Z]{1,3}\$?[1-9][0-9]*';
        $range = $cell.'(?::'.$cell.')?';
        $value = preg_replace('/Fuente:\s*Excel\s+Hoja[0-9]+!'.$range.'(?:,\s*columnas?\s+'.$range.')?[.;]?\s*/iu', '', $value);
        $value = preg_replace('/\bHoja[0-9]+!'.$range.'\b/iu', '', $value);
        $value = preg_replace('/\bcolumnas?\s+'.$range.'(?:\s*[:–-]\s*'.$range.')?[.;]?/iu', '', $value);

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $value));
    }

    /** Guardar otros metadatos no convierte la ocultación visual en una corrección de procedencia. */
    public static function preserveDescription(?string $original, ?string $submitted): ?string
    {
        return $original !== self::text($original) && ($submitted ?? '') === (self::text($original) ?? '')
            ? $original : $submitted;
    }

    /** Conserva la captura original cuando el profesional edita el texto que sí se presenta. */
    public static function preserveObservations(?string $original, ?string $submitted): ?string
    {
        if ($submitted === $original || ($submitted ?? '') === (self::text($original, false) ?? '')) {
            return $original;
        }
        $block = self::originalCellsBlock($original ?? '');

        return $block === null ? $submitted : rtrim(self::text($submitted, false) ?? '')."\n".$block;
    }

    /** Observación libre de Ornato: encabezado OBSERVACIONES verificado en el libro autorizado. */
    public static function capturedObservations(?string $value): ?string
    {
        $block = self::originalCellsBlock($value ?? '');
        if ($block === null) {
            return null;
        }
        $cells = json_decode(substr($block, strpos($block, '{')), true);
        $notes = $cells['DE'] ?? null;

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }

    /** Reconoce únicamente el bloque de procedencia generado por la carga histórica. */
    private static function originalCellsBlock(string $value): ?string
    {
        if (!preg_match('/^Celdas originales \(incluye valores calculados guardados por Excel\):[ \t]*(\{[^\r\n]*\})[ \t]*$/mu', $value, $match)) {
            return null;
        }
        $cells = json_decode($match[1], true);
        if (!is_array($cells) || $cells === []) {
            return null;
        }
        foreach (array_keys($cells) as $key) {
            if (!is_string($key) || !preg_match('/\A[A-Z]{1,3}\z/D', $key)) {
                return null;
            }
        }

        return $match[0];
    }

    public static function riskMessage(string $message): string
    {
        return str_starts_with($message, 'Selecciones incompatibles: ')
            ? preg_replace('/\b[A-Z]{1,3}\s+·\s*/u', '', $message) : $message;
    }

    /** El detalle de auditoría se presenta sin direcciones; el documento canónico no se modifica. */
    public static function configuration(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return self::text($json);
        }
        $strip = function (array $rows) use (&$strip): array {
            unset($rows['column']);
            foreach ($rows as $key => $value) {
                if (is_array($value)) {
                    $rows[$key] = $strip($value);
                }
            }

            return $rows;
        };

        return json_encode($strip($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
