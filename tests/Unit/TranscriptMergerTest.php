<?php

namespace Tests\Unit;

use App\Services\TranscriptMerger;
use PHPUnit\Framework\TestCase;

class TranscriptMergerTest extends TestCase
{
    public function test_merges_in_order_and_removes_only_edge_overlap(): void
    {
        $merged = (new TranscriptMerger)->merge([
            'Paciente refiere dolor de garganta desde ayer y niega fiebre.',
            'desde ayer y niega fiebre. Se indicó hidratación y reposo.',
            'Se indicó hidratación y reposo. Regresar si presenta dificultad respiratoria.',
        ]);

        $this->assertSame(
            "Paciente refiere dolor de garganta desde ayer y niega fiebre.\n\n".
            "Se indicó hidratación y reposo.\n\nRegresar si presenta dificultad respiratoria.",
            $merged
        );
    }
}
