<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CoordinateParserService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoordinateParserTest extends TestCase
{
    #[DataProvider('validCoordinates')]
    public function test_parses_decimal_and_dms_without_swapping_axes(
        string $raw,
        float $latitude,
        float $longitude,
        string $format,
    ): void {
        $parsed = (new CoordinateParserService())->parse($raw);

        $this->assertEqualsWithDelta($latitude, $parsed->latitude, 0.000000001);
        $this->assertEqualsWithDelta($longitude, $parsed->longitude, 0.000000001);
        $this->assertSame($raw, $parsed->raw);
        $this->assertSame($format, $parsed->format);
        $this->assertSame(['latitude', 'longitude', 'raw', 'format'], array_keys($parsed->toArray()));
    }

    public static function validCoordinates(): array
    {
        return [
            'decimal Colombia' => ['4.154, -73.633', 4.154, -73.633, 'decimal'],
            'decimal sur este' => ['-4.154, 73.633', -4.154, 73.633, 'decimal'],
            'signos explícitos y espacios conservados' => ["  +4.154 , -73.633  \n", 4.154, -73.633, 'decimal'],
            'origen de ejes válido' => ['0, 0', 0.0, 0.0, 'decimal'],
            'límites superiores' => ['90.000000, 180.0', 90.0, 180.0, 'decimal'],
            'límites inferiores' => ['-90, -180', -90.0, -180.0, 'decimal'],
            'DMS norte oeste' => ['4°15\'19.2" N, 73°34\'19.1" W', 4.255333333333333, -73.57197222222223, 'dms'],
            'DMS sur este' => ['4°15\'19.2" S, 73°34\'19.1" E', -4.255333333333333, 73.57197222222223, 'dms'],
            'DMS norte este' => ['4°15\'19.2" N, 73°34\'19.1" E', 4.255333333333333, 73.57197222222223, 'dms'],
            'DMS sur oeste' => ['4°15\'19.2" S, 73°34\'19.1" W', -4.255333333333333, -73.57197222222223, 'dms'],
            'primas Unicode y minúsculas' => ['4° 15′ 19.2″ n, 73° 34′ 19.1″ w', 4.255333333333333, -73.57197222222223, 'dms'],
            'DMS sin coma con hemisferios inequívocos' => ['4°15\'19.2"N 73°34\'19.1"W', 4.255333333333333, -73.57197222222223, 'dms'],
            'DMS límites con ceros' => ['90°0\'0"N, 180°0\'0"W', 90.0, -180.0, 'dms'],
            'DMS cero occidental' => ['0°0\'0"S, 0°0\'0"W', 0.0, 0.0, 'dms'],
        ];
    }

    #[DataProvider('invalidCoordinates')]
    public function test_rejects_ambiguous_non_finite_out_of_range_or_incomplete_input(string $raw): void
    {
        try {
            (new CoordinateParserService())->parse($raw);
            $this->fail('Se aceptó una coordenada inválida: '.$raw);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coordinate_raw', $exception->errors());
            $this->assertNotEmpty($exception->errors()['coordinate_raw'][0]);
        }
    }

    public static function invalidCoordinates(): array
    {
        return array_map(fn (string $value): array => [$value], [
            '', '   ', '4.154', '4.154 -73.633', '4,154, -73,633', '4.154; -73.633',
            '4.154, -73.633, 1', 'NaN, 1', '1, INF', '-INF, 1', '1e0, 2',
            '91, 1', '-90.01, 1', '1, 180.0001', '1, -181',
            '90.000000000000000000001, 0', '0, -180.000000000000000000001',
            '4.154N, -73.633W', 'texto 4.154, -73.633', '4.154, -73.633 texto',
            '4°60\'0"N, 73°0\'0"W', '4°0\'60"N, 73°0\'0"W',
            '4°0\'0"N, 73°60\'0"W', '4°0\'0"N, 73°0\'60"W',
            '91°0\'0"N, 73°0\'0"W', '4°0\'0"N, 181°0\'0"W',
            '90°0\'0.1"N, 73°0\'0"W', '4°0\'0"N, 180°0\'0.1"W',
            '-4°15\'19.2"S, 73°34\'19.1"W', '+4°15\'19.2"N, 73°34\'19.1"E',
            '4°15\'19.2"E, 73°34\'19.1"N', '4°15\'19.2"N, 73°34\'19.1"S',
            '4°15\'19.2", 73°34\'19.1"W', '4°15\'19.2"Q, 73°34\'19.1"W',
            '4°15\'19.2"N, 73°34\'19.1"', '4°15\'N, 73°34\'W',
            '4°15\'19,2"N, 73°34\'19,1"W', '4°-1\'0"N, 73°0\'0"W',
            '4°0\'-1"N, 73°0\'0"W', '4°0\'0"N, -73°0\'0"W',
        ]);
    }

    public function test_json_keeps_source_text_and_numeric_coordinates(): void
    {
        $parsed = (new CoordinateParserService())->parse('4.154, -73.633');

        $this->assertSame(
            ['latitude' => 4.154, 'longitude' => -73.633, 'raw' => '4.154, -73.633', 'format' => 'decimal'],
            json_decode(json_encode($parsed, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
