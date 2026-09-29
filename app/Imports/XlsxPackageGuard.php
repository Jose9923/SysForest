<?php

declare(strict_types=1);

namespace App\Imports;

use Illuminate\Validation\ValidationException;
use XMLReader;
use ZipArchive;

/** Inspecciona OOXML acotado sin extraer archivos, seguir enlaces ni resolver entidades externas. */
final class XlsxPackageGuard
{
    public function inspect(string $path): array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > (int) config('imports.max_upload_kb', 5120) * 1024) {
            $this->fail('El archivo supera el tamaño permitido o no es un archivo regular.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail('El archivo no es un libro XLSX válido. Descargue la plantilla oficial.');
        }
        try {
            if ($zip->numFiles > (int) config('imports.max_zip_entries', 200)) {
                $this->fail('El libro contiene demasiados componentes internos.');
            }
            $size = 0;
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || !$this->allowedName($stat['name']) || isset($names[strtolower($stat['name'])])) {
                    $this->fail('El libro contiene componentes no admitidos o rutas duplicadas.');
                }
                $names[strtolower($stat['name'])] = true;
                $size += $stat['size'];
                if ($size > (int) config('imports.max_uncompressed_bytes', 33554432)
                    || ($stat['encryption_method'] ?? 0) !== 0 || !in_array($stat['comp_method'], [0, 8], true)) {
                    $this->fail('El libro excede el límite descomprimido o usa cifrado/compresión no admitidos.');
                }
            }
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                $bytes = $zip->getFromIndex($index, $stat['size'] + 1);
                if ($bytes === false || strlen($bytes) !== $stat['size'] || str_contains($bytes, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $bytes)) {
                    $this->fail('El libro contiene XML inválido o declaraciones de entidades no admitidas.');
                }
                $this->validateXml($bytes, str_ends_with($name, '.rels'));
                $entries[$name] = $bytes;
            }
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
                if (!isset($entries[$required])) {
                    $this->fail('Faltan componentes obligatorios de la plantilla XLSX.');
                }
            }
            if (!str_contains($entries['[Content_Types].xml'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml')
                || preg_match('/macroEnabled|vbaProject|oleObject|activeX|externalLink/i', $entries['[Content_Types].xml'])) {
                $this->fail('Solo se admiten libros XLSX sin macros, objetos ni vínculos externos.');
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /** Lista cerrada: tampoco se admiten imágenes, comentarios, conexiones ni binarios incrustados. */
    private function allowedName(string $name): bool
    {
        return preg_match('~\A(?:\[Content_Types\]\.xml|_rels/\.rels|docProps/(?:app|core|custom)\.xml|xl/(?:workbook|styles|sharedStrings)\.xml|xl/_rels/workbook\.xml\.rels|xl/theme/theme[1-9][0-9]*\.xml|xl/worksheets/sheet[1-9][0-9]*\.xml)\z~D', $name) === 1;
    }

    private function validateXml(string $bytes, bool $relationships): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader();
        try {
            if (!$reader->XML($bytes, null, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->fail('El libro contiene XML mal formado.');
            }
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->nodeType === XMLReader::ENTITY_REF) {
                    $this->fail('No se admiten entidades ni DTD en el libro.');
                }
                if ($reader->depth > 30) {
                    $this->fail('El XML del libro excede la profundidad permitida.');
                }
                if ($relationships && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                    $target = $reader->getAttribute('Target') ?? '';
                    $type = $reader->getAttribute('Type') ?? '';
                    if (strcasecmp($reader->getAttribute('TargetMode') ?? '', 'External') === 0
                        || preg_match('~\A(?:[a-z][a-z0-9+.-]*:|//)|\\\\|\x00~i', $target)
                        || preg_match('~/(?:hyperlink|externalLink|oleObject|vbaProject|image|attachedTemplate|package)\z~i', $type)) {
                        $this->fail('No se admiten enlaces externos, macros, imágenes ni objetos en la plantilla.');
                    }
                }
            }
            if (libxml_get_errors() !== []) {
                $this->fail('El libro contiene XML mal formado.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
