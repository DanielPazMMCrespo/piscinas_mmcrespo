<?php

namespace Tests\Unit;

use App\Services\GeminiAnalysisService;
use PHPUnit\Framework\TestCase;

class GeminiAnalysisServiceTest extends TestCase
{
    /**
     * Testa a normalização de valores numéricos de caligrafia portuguesa
     */
    public function test_normalize_numeric_values(): void
    {
        // Números com vírgulas (padrão em PT)
        $this->assertEquals(7.35, GeminiAnalysisService::normalizeNumeric('7,35'));
        $this->assertEquals(1.25, GeminiAnalysisService::normalizeNumeric('1,25'));

        // Números com sufixos ou unidades
        $this->assertEquals(1.25, GeminiAnalysisService::normalizeNumeric('1,25 bar'));
        $this->assertEquals(14352.8, GeminiAnalysisService::normalizeNumeric('14352,80 m3'));

        // Valores nulos ou inválidos
        $this->assertNull(GeminiAnalysisService::normalizeNumeric(null));
        $this->assertNull(GeminiAnalysisService::normalizeNumeric(''));
        $this->assertNull(GeminiAnalysisService::normalizeNumeric('não aplicável'));
    }

    /**
     * Testa a normalização do array de resposta do Gemini
     */
    public function test_normalize_response_data(): void
    {
        $rawPressao = [
            'pressao_1' => '1,65 bar',
            'pressao_2' => '1,50',
            'confidence' => '0.95',
        ];

        $normalized = GeminiAnalysisService::normalizeResponse($rawPressao, 'pressao');

        $this->assertEquals(1.65, $normalized['pressao_1']);
        $this->assertEquals(1.50, $normalized['pressao_2']);
        $this->assertEquals(0.95, $normalized['confidence']);

        $rawAnalise = [
            'ph' => '7,4',
            'cloro_livre' => '1,2',
            'cloro_total' => '1,5',
            'temperatura' => '28,5 ºC',
            'transparencia' => '3 metros',
            'confidence' => 0.90,
        ];

        $normalizedAnalise = GeminiAnalysisService::normalizeResponse($rawAnalise, 'analise_ns');

        $this->assertEquals(7.4, $normalizedAnalise['ph']);
        $this->assertEquals(1.2, $normalizedAnalise['cloro_livre']);
        $this->assertEquals(1.5, $normalizedAnalise['cloro_total']);
        $this->assertEquals(28.5, $normalizedAnalise['temperatura']);
        $this->assertEquals(3, $normalizedAnalise['transparencia']);
    }
}
