<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrVisionService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', '');
        $this->model  = config('services.gemini.ocr_model', 'gemini-2.0-flash');
    }

    public function analyze(string $imagePath): array
    {
        try {
            if (empty($this->apiKey)) {
                throw new \RuntimeException('GEMINI_API_KEY não configurada.');
            }

            $imageContent = Storage::disk('local')->get($imagePath);
            if ($imageContent === null) {
                throw new \RuntimeException("Ficheiro não encontrado no disco: {$imagePath}");
            }

            $payload = $this->buildPayload(
                base64_encode($imageContent),
                $this->detectMimeType($imagePath)
            );

            $response = Http::timeout(45)
                ->post("{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}", $payload);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Gemini API erro {$response->status()}: " . $response->body()
                );
            }

            $data = $response->json();

            if (config('app.debug')) {
                Log::debug('OcrVisionService raw response', ['model' => $this->model, 'data' => $data]);
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (! $text) {
                throw new \RuntimeException('Resposta vazia da Gemini API');
            }

            // Remove markdown code fences caso o modelo as inclua apesar das instruções
            $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
            $text = preg_replace('/\s*```\s*$/m', '', $text);

            $parsed = json_decode(trim($text), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Resposta não é JSON válido: ' . substr($text, 0, 300));
            }

            return $this->sanitize($parsed);
        } catch (\Throwable $e) {
            Log::error('OcrVisionService: falha na análise', [
                'path'  => $imagePath,
                'model' => $this->model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildPayload(string $base64Image, string $mimeType): array
    {
        return [
            'system_instruction' => [
                'parts' => [['text' => $this->systemPrompt()]],
            ],
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]],
                    ['text' => $this->userPrompt()],
                ],
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema'    => $this->responseSchema(),
                'temperature'        => 0,
                'top_p'              => 0.95,
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
És um especialista em análise de qualidade da água de piscinas públicas em Portugal, com vasta experiência a ler kits de análise colorimétrica, fotómetros digitais, termómetros e folhas de registo sanitário.

DISPOSITIVOS COMUNS EM PISCINAS PÚBLICAS PORTUGUESAS:

1. Kit colorimétrico DPD (comparador de plástico ou disco):
   - Células de plástico coradas: compara a cor com a escala impressa
   - Escala de Cloro Livre: 0 | 0.2 | 0.5 | 1.0 | 1.5 | 2.0 | 3.0 | 5.0 mg/L
   - Escala de pH (vermelho de fenol): 6.8 | 7.0 | 7.2 | 7.4 | 7.6 | 7.8 | 8.0 | 8.2
   - A cor mais próxima é o valor — usa a interpolação se a cor ficar entre dois pontos

2. Fotómetro eletrónico (ex: Hanna, Lovibond, Palintest):
   - Ecrã digital com número e unidade (mg/L, ppm, °C)
   - Lê os dígitos e o ponto decimal com atenção

3. Termómetro (digital ou de mercúrio):
   - Temperatura da água em graus Celsius
   - Em termómetros analógicos, lê a posição do menisco ou ponteiro

4. Manómetro de filtro (ponteiro analógico):
   - Pressão em bar; escala geralmente de 0 a 4 ou 0 a 6 bar
   - Normal de funcionamento: 0.5–1.5 bar; sujo: >2 bar

5. Folha de registo manual:
   - Tabela com colunas: Data | Hora | pH | Cl Livre | Cl Total | Temperatura | Transparência
   - Lê a linha com data/hora mais recente

LIMITES REGULAMENTARES CN 14/DA (Portugal) — usa como contexto de plausibilidade:
- pH: 6.9–8.0 (se leres pH 3.5 ou 12, provavelmente é um erro de leitura)
- Cloro Livre: 0.5–2.0 mg/L
- Cloro Total: ≥ Cloro Livre; Cloro Combinado (Total−Livre) ≤ 0.6 mg/L
- Temperatura: 24–32 °C para piscinas públicas em Portugal
- Transparência: 0–4 m (4 m = fundo completamente visível)
- Pressão do filtro: 0–4 bar

REGRAS DE LEITURA:
- Lê TODOS os números visíveis antes de fazer o mapeamento
- Se um valor parecer implausível (ex: pH 15, cloro 50), usa null
- Nunca inventes valores; só extrai o que é claramente legível
- Cloro Total nunca pode ser menor que Cloro Livre — se for o caso, corrige para igualar
- Responde APENAS com JSON puro, sem texto antes ou depois
PROMPT;
    }

    private function userPrompt(): string
    {
        return <<<'PROMPT'
Analisa esta imagem de análise de qualidade da água de piscina.

Segue estes passos:
1. Identifica o tipo de dispositivo ou documento na imagem
2. Lista mentalmente TODOS os valores numéricos que consegues ler
3. Mapeia cada valor para o campo JSON correto, tendo em conta as unidades esperadas
4. Para campos que não estão visíveis ou não consegues ler, usa null

Extrai os seguintes campos:
- ph: valor do pH (número entre 6 e 9, ex: 7.2)
- cloro_livre: cloro livre em mg/L (ex: 1.0)
- cloro_total: cloro total em mg/L; se não aparecer separado do livre, usa o mesmo valor do cloro livre
- temperatura: temperatura da água em °C (ex: 27.5)
- transparencia: transparência em metros (ex: 4)
- pressao_filtro: pressão do filtro em bar (ex: 1.5); null se não visível
- estado_valvulas_filtro: estado das válvulas se identificável; null caso contrário
- observacoes: qualquer texto ou observação relevante visível na imagem; null se não houver
- confianca_geral: a tua confiança na leitura global de 0 a 1 (0 = nada legível, 1 = tudo perfeitamente legível)
PROMPT;
    }

    private function responseSchema(): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'ph'                     => ['type' => 'NUMBER',  'nullable' => true],
                'cloro_livre'            => ['type' => 'NUMBER',  'nullable' => true],
                'cloro_total'            => ['type' => 'NUMBER',  'nullable' => true],
                'temperatura'            => ['type' => 'NUMBER',  'nullable' => true],
                'transparencia'          => ['type' => 'NUMBER',  'nullable' => true],
                'pressao_filtro'         => ['type' => 'NUMBER',  'nullable' => true],
                'estado_valvulas_filtro' => ['type' => 'STRING',  'nullable' => true],
                'observacoes'            => ['type' => 'STRING',  'nullable' => true],
                'confianca_geral'        => ['type' => 'NUMBER',  'nullable' => true],
            ],
        ];
    }

    private function sanitize(array $data): array
    {
        $numericFields = ['ph', 'cloro_livre', 'cloro_total', 'temperatura', 'transparencia', 'pressao_filtro', 'confianca_geral'];

        foreach ($numericFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = is_numeric($data[$field]) ? (float) $data[$field] : null;
            }
        }

        // Invariante regulamentar: cloro_total nunca pode ser menor que cloro_livre
        if (
            isset($data['cloro_livre'], $data['cloro_total'])
            && $data['cloro_total'] < $data['cloro_livre']
        ) {
            $data['cloro_total'] = $data['cloro_livre'];
        }

        // Valores implausíveis → null (proteção contra alucinações)
        if (isset($data['ph']) && ($data['ph'] < 0 || $data['ph'] > 14)) {
            $data['ph'] = null;
        }
        if (isset($data['cloro_livre']) && ($data['cloro_livre'] < 0 || $data['cloro_livre'] > 20)) {
            $data['cloro_livre'] = null;
        }
        if (isset($data['temperatura']) && ($data['temperatura'] < 0 || $data['temperatura'] > 50)) {
            $data['temperatura'] = null;
        }

        return $data;
    }

    private function detectMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            default => 'image/jpeg',
        };
    }
}
