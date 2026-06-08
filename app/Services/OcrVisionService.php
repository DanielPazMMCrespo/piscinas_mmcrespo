<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrVisionService
{
    private string $apiKey;
    private string $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', '');
    }

    /**
     * Analisa uma foto de folha de análise de água ou dispositivo de medição.
     * Retorna os valores extraídos ou lança exceção se a API falhar.
     */
    public function analyze(string $imagePath): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new \RuntimeException('GEMINI_API_KEY não configurada em config/services.php');
            }

            $imageContent = Storage::disk('local')->get($imagePath);

            if ($imageContent === null) {
                throw new \RuntimeException("Ficheiro não encontrado no disco: {$imagePath}");
            }

            $base64Image = base64_encode($imageContent);
            $mimeType = $this->detectMimeType($imagePath);

            $payload = [
                'system_instruction' => [
                    'parts' => [[
                        'text' => 'You are an expert at reading pool water analysis sheets and measurement devices. '
                            . 'You MUST respond with valid JSON only. No markdown code blocks, no explanations, just raw JSON. '
                            . 'If a value is not visible or legible, use null for that field.',
                    ]],
                ],
                'contents' => [[
                    'parts' => [
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data'      => $base64Image,
                            ],
                        ],
                        [
                            'text' => 'Analyze this pool water analysis sheet or measurement device photo. '
                                . 'Extract the following values if visible and respond ONLY with this exact JSON structure: '
                                . '{"ph": number_or_null, "cloro_livre": number_or_null, "cloro_total": number_or_null, '
                                . '"temperatura": number_or_null, "transparencia": number_or_null, '
                                . '"pressao_filtro": number_or_null, "estado_valvulas_filtro": "string_or_null", '
                                . '"observacoes": "string_or_null"}',
                        ],
                    ],
                ]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                    'temperature'        => 0.1,
                ],
            ];

            $response = Http::timeout(30)
                ->post("{$this->endpoint}?key={$this->apiKey}", $payload);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Gemini API devolveu erro {$response->status()}: " . $response->body()
                );
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (! $text) {
                throw new \RuntimeException('Resposta vazia da Gemini API');
            }

            $parsed = json_decode(trim($text), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Resposta da Gemini não é JSON válido: ' . $text);
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('OcrVisionService: falha na análise', [
                'path'  => $imagePath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function detectMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png'        => 'image/png',
            'gif'        => 'image/gif',
            'webp'       => 'image/webp',
            'heic'       => 'image/heic',
            'heif'       => 'image/heif',
            default      => 'image/jpeg',
        };
    }
}
