<?php

declare(strict_types=1);

namespace App\Reports;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Geojson = 'geojson';
    case Pdf = 'pdf';

    public function mime(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Geojson => 'application/geo+json',
            self::Pdf => 'application/pdf',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV', self::Xlsx => 'Excel', self::Geojson => 'GeoJSON', self::Pdf => 'PDF',
        };
    }
}
