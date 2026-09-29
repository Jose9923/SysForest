<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TreeCaptureDateService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TreeCaptureDateServiceTest extends TestCase
{
    public function test_local_input_and_display_do_not_mutate_the_original_timestamp(): void
    {
        $service = new TreeCaptureDateService();
        $timestamp = CarbonImmutable::parse('2026-09-28T07:30:15.123456Z');
        $this->assertSame('2026-09-28T02:30:15.123', $service->inputValue($timestamp));
        $this->assertSame('28/09/2026 02:30:15.123456 (hora de Colombia)', $service->displayValue($timestamp));
        $this->assertSame('2026-09-28T07:30:15.123456+00:00', $timestamp->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('', $service->inputValue(null));
        $this->assertNull($service->displayValue(null));
    }

    public function test_original_iso_contract_and_omitted_field_are_untouched(): void
    {
        $service = new TreeCaptureDateService();
        $this->assertSame([], $service->normalizeInput([]));
        $input = ['captured_at' => '2026-09-28T12:30:15.654321+03:00'];
        $this->assertSame($input, $service->normalizeInput($input));
    }

    public function test_conversion_ignores_the_application_and_server_default_timezone(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Tokyo');
            $values = (new TreeCaptureDateService())->normalizeInput(['captured_at_local' => '2026-09-28T23:30']);
            $this->assertSame(['captured_at' => '2026-09-29T04:30:00.000000+00:00'], $values);
        } finally {
            date_default_timezone_set($original);
        }
    }
}
