<?php

namespace Tests\Unit;

use App\Services\ProcessingTimeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProcessingTimeServiceTest extends TestCase
{
    #[DataProvider('ranges')]
    public function test_classifies_all_boundaries(float $seconds, int $range, string $label): void
    {
        $this->assertSame([$range, $label], (new ProcessingTimeService)->classifyProcessingTime($seconds));
    }

    public static function ranges(): array
    {
        return [
            'zero' => [0, 5, 'Muy rápido'],
            'thirty' => [30, 5, 'Muy rápido'],
            'after thirty' => [30.001, 4, 'Rápido'],
            'sixty' => [60, 4, 'Rápido'],
            'after sixty' => [60.001, 3, 'Moderado'],
            'one hundred twenty' => [120, 3, 'Moderado'],
            'after one hundred twenty' => [120.001, 2, 'Lento'],
            'one hundred eighty' => [180, 2, 'Lento'],
            'after one hundred eighty' => [180.001, 1, 'Muy lento'],
        ];
    }
}
