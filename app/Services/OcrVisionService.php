<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OCR síncrono via Google Gemini 1.5 Flash Vision.
 * Usado nos uploads de foto do DailyRecordResource para pré-preencher campos.
 */
class OcrVisionService
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

    /**
     * Executa um pedido síncrono à API Gemini com as partes (parts) dadas.
     * Força JSON estrito via responseMimeType.
     *
     * @throws \RuntimeException se a API falhar ou a resposta não for JSON válido
     */
    private static function call(array $parts): array
    {
        $key = config('services.gemini.key');

        if (empty($key)) {
            Log::warning('OcrVisionService: GEMINI_API_KEY não configurada.');

            return [];
        }

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post(self::API_BASE.'?key='.$key, [
                'contents' => [
                    ['parts' => $parts],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Gemini API error: '.$response->body());
        }

        $raw = $response->json('candidates.0.content.parts.0.text', '{}');

        // Strip accidental markdown fences if present.
        $raw = preg_replace('/```json\s*/i', '', $raw);
        $raw = preg_replace('/```\s*/', '', $raw);

        $decoded = json_decode(trim($raw), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Gemini returned invalid JSON: '.$raw);
        }

        return $decoded;
    }

    /**
     * Constrói um bloco inline_data a partir de um caminho relativo no disco 'local'.
     */
    private static function imagePart(string $diskPath): array
    {
        $raw = Storage::disk('local')->get($diskPath);
        $mime = Storage::disk('local')->mimeType($diskPath) ?: 'image/jpeg';

        return [
            'inline_data' => [
                'mime_type' => $mime,
                'data' => base64_encode($raw),
            ],
        ];
    }

    /**
     * Constrói um bloco inline_data a partir de um caminho absoluto do sistema de ficheiros
     * (usado para imagens de referência em storage/app/referencias_ia/).
     */
    private static function imagePartFromAbsPath(string $absPath): array
    {
        $raw = file_get_contents($absPath);
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return [
            'inline_data' => [
                'mime_type' => $mime,
                'data' => base64_encode($raw),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Métodos públicos
    // -------------------------------------------------------------------------

    /**
     * Extrai parâmetros da água (pH, cloro, temperatura, transparência) de uma foto
     * de boletim de análises manuscrito ou de ecrã digital de fotómetro.
     *
     * @param  string  $imagePath  Caminho relativo no disco 'local'
     * @return array Chaves: ph, cloro_livre, cloro_total, temperatura, transparencia
     */
    public static function analyzeWaterParameters(string $imagePath): array
    {
        $parts = [
            self::imagePart($imagePath),
            [
                'text' => 'Extract water quality parameters from this pool analysis sheet or photometer screen. '
                        .'Return a JSON object with these exact keys (use null for any value not visible): '
                        .'{"ph": float_or_null, "cloro_livre": float_or_null, "cloro_total": float_or_null, '
                        .'"temperatura": float_or_null, "transparencia": integer_or_null}. '
                        .'Decimal separator must be a period (.). Transparencia is in meters (integer).',
            ],
        ];

        return self::call($parts);
    }

    /**
     * Classifica o estado da bomba circuladora e detecta presença de bolhas de ar.
     *
     * @param  string  $imagePath  Caminho relativo no disco 'local'
     * @return array Chaves: estado ("ferrada" | "desferrada"), com_bolhas (boolean)
     */
    public static function analyzePump(string $imagePath): array
    {
        $parts = [
            self::imagePart($imagePath),
            [
                'text' => 'Analyze this image of a swimming pool circulation pump (focus on the transparent pre-filter basket and acrylic sight glass). '
                        .'Determine: (1) if the pump is "ferrada" (water circulating — basket full of water, no large air bubbles) '
                        .'or "desferrada" (no circulation — basket empty or full of air, pump stopped or priming). '
                        .'(2) whether there are visible air bubbles (com_bolhas). '
                        .'Return JSON: {"estado": "ferrada" or "desferrada", "com_bolhas": true or false}.',
            ],
        ];

        return self::call($parts);
    }

    /**
     * Lê as pressões dos manómetros, detecta posição da válvula seletora e estado das válvulas.
     * Inclui imagens de referência de storage/app/referencias_ia/ (se existirem) como few-shot.
     *
     * @param  string  $uploadedImagePath  Caminho relativo no disco 'local'
     * @return array Chaves: pressao_1, pressao_2, posicao_valvula, estado_valvulas
     */
    public static function analyzeFilters(string $uploadedImagePath): array
    {
        $refDir = storage_path('app/referencias_ia');
        $refTypes = [
            'filtro_posicao_normal' => 'FILTRAÇÃO (posição normal)',
            'filtro_lavagem' => 'LAVAGEM / Backwash',
            'filtro_enxaguamento' => 'ENXAGUAMENTO / Rinse',
        ];

        $parts = [];

        // --- Imagens de referência (few-shot visual) ---
        $hasRefs = false;
        foreach ($refTypes as $basename => $label) {
            foreach (['jpg', 'jpeg', 'png'] as $ext) {
                $absPath = "{$refDir}/{$basename}.{$ext}";
                if (file_exists($absPath)) {
                    if (! $hasRefs) {
                        $parts[] = [
                            'text' => 'The following images are REFERENCE examples showing different multiport valve positions on a pool filter:',
                        ];
                        $hasRefs = true;
                    }
                    $parts[] = self::imagePartFromAbsPath($absPath);
                    $parts[] = ['text' => "Reference valve position: {$label}"];
                    break;
                }
            }
        }

        // --- Imagem carregada pelo utilizador ---
        $parts[] = [
            'text' => $hasRefs
                ? 'Now analyze the following uploaded image from the pool technician:'
                : 'Analyze the following image of pool filter equipment:',
        ];
        $parts[] = self::imagePart($uploadedImagePath);
        $parts[] = [
            'text' => 'Read both pressure gauges (left gauge = pressao_1, right gauge = pressao_2, values in bar). '
                    .'If you can see the multiport valve selector, identify its position. '
                    .'Also note the general condition of the valves and filter (estado_valvulas: short description or null). '
                    .'Return JSON: {"pressao_1": float_or_null, "pressao_2": float_or_null, '
                    .'"posicao_valvula": "normal" | "lavagem" | "enxaguamento" | "outro" | null, '
                    .'"estado_valvulas": string_or_null}. '
                    .'Decimal separator must be a period (.).',
        ];

        return self::call($parts);
    }
}
