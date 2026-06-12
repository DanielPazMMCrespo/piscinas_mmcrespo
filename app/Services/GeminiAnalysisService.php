<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiAnalysisService
{
    /**
     * Executa a análise da foto via Gemini com consenso multi-agente e múltiplas fotos.
     *
     * @param  string|array  $paths  Caminho relativo ou array de caminhos no disco 'local'
     * @param  string  $type  Tipo de foto (bomba, pressao, filtro_lavagem, filtro_enxaguamento,
     *                        filtro_posicao_normal, contador, tanque, analise_ns, analise_nossa,
     *                        turbidimetro, rotulo)
     * @param  array  $context  Contexto opcional: pool_name, now (hora HH:MM), installation
     * @return array Resultado estruturado com os dados extraídos
     */
    public static function analyze($paths, string $type, array $context = []): array
    {
        $apiKey = config('services.gemini.key');

        if (! $apiKey) {
            Log::warning("Gemini API key não configurada. Devolvendo dados simulados (mock) para teste do tipo: {$type}");

            return self::getMockResponse($type);
        }

        try {
            $pathsArray = is_array($paths) ? $paths : [$paths];
            $imagesData = [];

            foreach ($pathsArray as $path) {
                if (! Storage::disk('local')->exists($path)) {
                    throw new \Exception("Ficheiro de imagem não encontrado no disco local: {$path}");
                }
                $rawImage = Storage::disk('local')->get($path);
                $imagesData[] = [
                    'mimeType' => Storage::disk('local')->mimeType($path) ?: 'image/jpeg',
                    'base64' => base64_encode($rawImage),
                ];
            }

            $threshold = config('services.gemini.confidence_threshold', 0.85);

            // Agente 1 (principal) — temperatura 0.0 para máximo determinismo.
            $response = self::queryGemini($imagesData, $type, $apiKey, 0.0, $context);
            $response = self::normalizeResponse($response, $type);

            $confidence = $response['confidence'] ?? 1.0;

            // Confiança baixa -> rede de consenso (mais 2 agentes independentes).
            if ($confidence < $threshold) {
                Log::info("Confiança da IA baixa ({$confidence} < {$threshold}) para tipo '{$type}'. Iniciando rede de consenso...");

                $response2 = self::normalizeResponse(self::queryGemini($imagesData, $type, $apiKey, 0.3, $context), $type);
                $response3 = self::normalizeResponse(self::queryGemini($imagesData, $type, $apiKey, 0.7, $context), $type);

                return self::calculateConsensus([$response, $response2, $response3], $type);
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('Erro na análise da IA (Gemini): '.$e->getMessage());

            // Fallback para mock em caso de erro de rede/cota, para não quebrar a UX.
            return self::getMockResponse($type);
        }
    }

    /**
     * Envia o prompt específico e as imagens para a API Gemini.
     */
    private static function queryGemini(array $imagesData, string $type, string $apiKey, float $temperature, array $context = []): array
    {
        $prompt = self::getPromptForType($type, $context);
        $model = config('services.gemini.model', 'gemini-1.5-pro');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $parts = [];

        // Few-shot: imagem de referência (e resposta esperada, se existir).
        [$refImagePath, $refJsonPath] = self::referencePaths($type, $context);

        if ($refImagePath !== null) {
            Log::info("A injetar exemplo de referência Few-Shot para o tipo '{$type}' ({$refImagePath})");
            $parts[] = ['text' => 'Aqui tens um EXEMPLO DE REFERÊNCIA (gabarito) de como deves ler este tipo de imagem. Estuda-o com atenção.'];
            $parts[] = [
                'inlineData' => [
                    'mimeType' => self::mimeFromPath($refImagePath),
                    'data' => base64_encode(file_get_contents($refImagePath)),
                ],
            ];
            if ($refJsonPath !== null) {
                $parts[] = ['text' => "RESPOSTA ESPERADA PARA O EXEMPLO ACIMA:\n".file_get_contents($refJsonPath)];
            }
            $parts[] = ['text' => "Agora analisa as imagens reais seguintes, aplicando a MESMA lógica, rigor e escala do exemplo:\n"];
        }

        $parts[] = ['text' => $prompt];

        foreach ($imagesData as $img) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $img['mimeType'],
                    'data' => $img['base64'],
                ],
            ];
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => $temperature,
            ],
        ];

        $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

        if ($response->failed()) {
            throw new \Exception('Erro HTTP Gemini API: '.$response->body());
        }

        $result = $response->json();
        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

        // Remove blocos de markdown caso o Gemini os tenha adicionado.
        $text = preg_replace('/```json\s*/i', '', $text);
        $text = preg_replace('/```\s*/', '', $text);

        $parsedData = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Gemini retornou um JSON inválido. Resposta crua: '.$text);
            throw new \Exception('Gemini retornou um JSON inválido: '.$text);
        }

        return $parsedData;
    }

    /**
     * Caminhos de referência few-shot. Aceita .jpg/.jpeg/.png e torna o .json opcional
     * (serve só de exemplo visual quando não há resposta esperada).
     *
     * @return array{0: string|null, 1: string|null} [imagemPath|null, jsonPath|null]
     */
    private static function referencePaths(string $type, array $context = []): array
    {
        $base = self::referenceBaseName($type, $context);
        $dir = storage_path('app/referencias_ia');

        $imagePath = null;
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            $candidate = "{$dir}/{$base}.{$ext}";
            if (file_exists($candidate)) {
                $imagePath = $candidate;
                break;
            }
        }

        if ($imagePath === null) {
            return [null, null];
        }

        $jsonPath = "{$dir}/{$base}.json";

        return [$imagePath, file_exists($jsonPath) ? $jsonPath : null];
    }

    /**
     * Nome-base do ficheiro de referência. O boletim das análises do NS em Leiria
     * tem as 3 piscinas juntas, por isso usa o gabarito 'boletim_ns'.
     */
    private static function referenceBaseName(string $type, array $context = []): string
    {
        $instalacao = strtolower((string) ($context['installation'] ?? ''));

        if ($type === 'analise_ns' && str_contains($instalacao, 'leiria')) {
            return 'boletim_ns';
        }

        return $type;
    }

    private static function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Prompts estruturados e especializados com Chain-of-Thought (pensar passo a passo).
     */
    private static function getPromptForType(string $type, array $context = []): string
    {
        $commonInstructions = 'Responde APENAS com um objeto JSON válido, sem usar markdown (sem ```json ou ```). ';

        switch ($type) {
            case 'bomba':
                return $commonInstructions."Instrução: Analisa a foto da bomba circuladora da piscina (focando no pré-filtro transparente e no visor de acrílico). Determina se a bomba está 'ferrada' (com água a circular, cheia, sem bolhas de ar grandes) ou 'desferrada' (vazia, cheia de ar, desligada ou sem água a circular). Pensa passo a passo explicando o que vês no campo 'raciocinio'. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"estado\": \"ferrada\"|\"desferrada\", \"confidence\": decimal entre 0.0 e 1.0}";

            case 'pressao':
                return $commonInstructions."Instrução: Analisa a foto dos dois manómetros analógicos de pressão do filtro da piscina. Lê o valor exato apontado pelo ponteiro em cada manómetro (escala em bar ou psi, converte para bar se necessário). Coloca o manómetro do LADO ESQUERDO em 'pressao_1' e o do LADO DIREITO em 'pressao_2'. Pensa passo a passo no campo 'raciocinio'. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"pressao_1\": decimal_bar, \"pressao_2\": decimal_bar, \"confidence\": decimal entre 0.0 e 1.0}. Se apenas vires 1 manómetro, define pressao_2 como null.";

            case 'filtro_lavagem':
            case 'filtro_enxaguamento':
            case 'filtro_posicao_normal':
                $alvo = match ($type) {
                    'filtro_lavagem' => 'LAVAGEM / Retrolavagem (Backwash)',
                    'filtro_enxaguamento' => 'ENXAGUAMENTO (Rinse)',
                    default => 'FILTRAÇÃO / posição normal de funcionamento (Filter)',
                };

                return $commonInstructions."Instrução: Analisa a foto da válvula seletora multivias do filtro da piscina (a manete/seletor com as posições escritas à volta). Compara com o EXEMPLO DE REFERÊNCIA e diz se o seletor está apontado para a posição de {$alvo}. Pensa passo a passo no campo 'raciocinio'. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"posicao_detectada\": \"texto da posição que observas\", \"posicao_correta\": true ou false (true se está em {$alvo}), \"confidence\": decimal entre 0.0 e 1.0}";

            case 'contador':
                return $commonInstructions."Instrução: Analisa a foto do contador mecânico de água da rede da piscina. Faz o OCR dos dígitos pretos (metros cúbicos inteiros) e dígitos vermelhos (frações decimais). Pensa passo a passo no campo 'raciocinio' (explicando cada dígito lido). Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"leitura\": decimal, \"confidence\": decimal entre 0.0 e 1.0}";

            case 'tanque':
                return $commonInstructions."Instrução: Analisa a foto do interior do tanque de compensação da piscina. Localiza a régua de nível graduada de 0% a 100% ou a linha da água e estima a percentagem de preenchimento (0% = vazio, 100% = totalmente cheio). Pensa passo a passo no campo 'raciocinio'. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"nivel\": inteiro_percentagem, \"confidence\": decimal entre 0.0 e 1.0}";

            case 'analise_ns':
            case 'analise_nossa':
                $foco = '';
                if (! empty($context['pool_name'])) {
                    $piscina = $context['pool_name'];
                    $foco .= " A folha pode conter VÁRIAS piscinas em colunas/blocos diferentes. Extrai SÓ os valores da piscina chamada '{$piscina}'. Se tiveres dúvida sobre a coluna, escolhe aquela cujo cabeçalho mais se parece com '{$piscina}'.";
                }
                if (! empty($context['now'])) {
                    $hora = $context['now'];
                    $foco .= " Se houver várias leituras a horas diferentes, escolhe a leitura cuja HORA está mais próxima de {$hora}.";
                }

                return $commonInstructions."Instrução: Analisa a foto do boletim de análises (manuscrito ou impresso) ou do ecrã digital do fotómetro de medição.{$foco} Extrai os valores das seguintes métricas com a máxima precisão: pH, Cloro Livre (mg/L ou ppm), Cloro Total (mg/L ou ppm), Temperatura (ºC) e Transparência (m). Pensa passo a passo no campo 'raciocinio' indicando de que coluna/linha tiraste os valores. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"ph\": decimal_ou_null, \"cloro_livre\": decimal_ou_null, \"cloro_total\": decimal_ou_null, \"temperatura\": decimal_ou_null, \"transparencia\": inteiro_ou_null, \"confidence\": decimal entre 0.0 e 1.0}";

            case 'turbidimetro':
                return $commonInstructions."Instrução: Analisa a foto do visor LCD digital do turbidímetro. Faz o OCR do valor de turbidez (em NTU). Classifica a água de acordo com o valor lido: se for < 1 NTU é 'visivel' (água límpida), se for entre 1 e 5 NTU é 'semi_turva' e se for > 5 NTU é 'turva'. Pensa passo a passo no campo 'raciocinio'. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"turbidez_ntu\": decimal, \"estado\": \"visivel\"|\"semi_turva\"|\"turva\", \"confidence\": decimal entre 0.0 e 1.0}";

            case 'rotulo':
                return $commonInstructions."Instrução: Analisa a foto do RÓTULO da embalagem de um produto químico de piscina (marcas típicas AstralPool ou CTX). Extrai: o NOME do produto; a CONCENTRAÇÃO do princípio ativo em percentagem (ex.: cloro/hipoclorito 'XX%'); a DOSE de tratamento recomendada por metro cúbico de água (procura indicações como 'g/m3', 'mL/m3', 'por 10 m3', 'por 100 L' e converte sempre para a quantidade por 1 m3); e a UNIDADE do produto (kg, L, g, mL). Pensa passo a passo no campo 'raciocinio'. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"nome\": \"texto_ou_null\", \"concentracao\": decimal_percentagem_ou_null, \"dose_recomendada\": decimal_por_m3_ou_null, \"unidade\": \"texto_ou_null\", \"confidence\": decimal entre 0.0 e 1.0}";

            case 'daily':
                return $commonInstructions."Instrução: Analisa a foto que pode conter manómetros ou contador de água. Extrai os valores das seguintes métricas se visíveis: contador (leitura m3), pressao_1 (bar), pressao_2 (bar). Pensa passo a passo no campo 'raciocinio'. Retorna no formato JSON: {\"raciocinio\": \"explicação curta\", \"contador\": decimal_ou_null, \"pressao_1\": decimal_ou_null, \"pressao_2\": decimal_ou_null, \"confidence\": decimal entre 0.0 e 1.0}";

            default:
                return $commonInstructions.'Instrução: Analisa esta imagem e descreve o que vês no formato JSON: {"raciocinio": "descrição", "confidence": 1.0}';
        }
    }

    /**
     * Algoritmo de consenso (votação da maioria para categorias, mediana para números).
     */
    private static function calculateConsensus(array $responses, string $type): array
    {
        Log::info("Calculando consenso entre 3 agentes para tipo '{$type}'...");

        $final = [
            'raciocinio' => 'Consenso de 3 agentes (Agente 1: '.($responses[0]['confidence'] ?? 0).
                            ', Agente 2: '.($responses[1]['confidence'] ?? 0).
                            ', Agente 3: '.($responses[2]['confidence'] ?? 0).')',
            'confidence' => round(collect($responses)->avg('confidence'), 2),
            'consenso_aplicado' => true,
        ];

        switch ($type) {
            case 'bomba':
                $final['estado'] = self::getMajorityVote(collect($responses)->pluck('estado')->toArray(), 'ferrada');
                break;

            case 'pressao':
                $final['pressao_1'] = self::getMedianValue(collect($responses)->pluck('pressao_1')->filter()->toArray());
                $final['pressao_2'] = self::getMedianValue(collect($responses)->pluck('pressao_2')->filter()->toArray());
                break;

            case 'filtro_lavagem':
            case 'filtro_enxaguamento':
            case 'filtro_posicao_normal':
                $votos = collect($responses)->map(fn ($r) => ! empty($r['posicao_correta']) ? 'sim' : 'nao')->toArray();
                $final['posicao_correta'] = self::getMajorityVote($votos, 'nao') === 'sim';
                $final['posicao_detectada'] = self::getMajorityVote(collect($responses)->pluck('posicao_detectada')->filter()->toArray(), '');
                break;

            case 'contador':
                $final['leitura'] = self::getMedianValue(collect($responses)->pluck('leitura')->filter()->toArray());
                break;

            case 'tanque':
                $final['nivel'] = (int) self::getMedianValue(collect($responses)->pluck('nivel')->filter()->toArray());
                break;

            case 'analise_ns':
            case 'analise_nossa':
                $final['ph'] = self::getMedianValue(collect($responses)->pluck('ph')->filter()->toArray());
                $final['cloro_livre'] = self::getMedianValue(collect($responses)->pluck('cloro_livre')->filter()->toArray());
                $final['cloro_total'] = self::getMedianValue(collect($responses)->pluck('cloro_total')->filter()->toArray());
                $final['temperatura'] = self::getMedianValue(collect($responses)->pluck('temperatura')->filter()->toArray());
                $final['transparencia'] = (int) self::getMedianValue(collect($responses)->pluck('transparencia')->filter()->toArray());
                break;

            case 'turbidimetro':
                $final['turbidez_ntu'] = self::getMedianValue(collect($responses)->pluck('turbidez_ntu')->filter()->toArray());
                $final['estado'] = self::getMajorityVote(collect($responses)->pluck('estado')->toArray(), 'visivel');
                break;

            case 'rotulo':
                $final['concentracao'] = self::getMedianValue(collect($responses)->pluck('concentracao')->filter()->toArray());
                $final['dose_recomendada'] = self::getMedianValue(collect($responses)->pluck('dose_recomendada')->filter()->toArray());
                $final['unidade'] = self::getMajorityVote(collect($responses)->pluck('unidade')->filter()->toArray(), '');
                $final['nome'] = self::getMajorityVote(collect($responses)->pluck('nome')->filter()->toArray(), '');
                break;

            case 'daily':
                $final['contador'] = self::getMedianValue(collect($responses)->pluck('contador')->filter()->toArray());
                $final['pressao_1'] = self::getMedianValue(collect($responses)->pluck('pressao_1')->filter()->toArray());
                $final['pressao_2'] = self::getMedianValue(collect($responses)->pluck('pressao_2')->filter()->toArray());
                break;
        }

        return $final;
    }

    /**
     * Votação de maioria simples para valores string/enum.
     */
    private static function getMajorityVote(array $values, string $default): string
    {
        $values = array_filter($values, fn ($v) => $v !== null && $v !== '');
        if (empty($values)) {
            return $default;
        }
        $counts = array_count_values(array_map('strval', $values));
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Mediana de valores numéricos para descartar anomalias (outliers).
     */
    private static function getMedianValue(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v !== ''));
        if (empty($values)) {
            return null;
        }
        sort($values);
        $count = count($values);
        $middle = (int) floor(($count - 1) / 2);

        if ($count % 2) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle] + (float) $values[$middle + 1]) / 2.0;
    }

    /**
     * Respostas simuladas (mock) realistas quando a chave da API não está configurada.
     */
    private static function getMockResponse(string $type): array
    {
        $mock = [
            'raciocinio' => 'Simulação local (Gemini API Key ausente). Valores gerados com base em padrões de operação reais.',
            'confidence' => 0.99,
            'is_mock' => true,
        ];

        switch ($type) {
            case 'bomba':
                return $mock + ['estado' => 'ferrada'];
            case 'pressao':
                return $mock + ['pressao_1' => 1.62, 'pressao_2' => 1.55];
            case 'filtro_lavagem':
            case 'filtro_enxaguamento':
            case 'filtro_posicao_normal':
                return $mock + ['posicao_correta' => true, 'posicao_detectada' => '(simulado)'];
            case 'contador':
                return $mock + ['leitura' => 14352.80];
            case 'tanque':
                return $mock + ['nivel' => 75];
            case 'analise_ns':
            case 'analise_nossa':
                return $mock + [
                    'ph' => 7.35,
                    'cloro_livre' => 1.25,
                    'cloro_total' => 1.55,
                    'temperatura' => 28.2,
                    'transparencia' => 3,
                ];
            case 'turbidimetro':
                return $mock + ['turbidez_ntu' => 0.35, 'estado' => 'visivel'];
            case 'rotulo':
                return $mock + ['nome' => 'Cloro Líquido 13%', 'concentracao' => 13.0, 'dose_recomendada' => 5.0, 'unidade' => 'L'];
            case 'daily':
                return $mock + ['contador' => 14352.80, 'pressao_1' => 1.62, 'pressao_2' => 1.55];
            default:
                return $mock;
        }
    }

    /**
     * Normaliza valores numéricos que possam vir formatados com vírgulas (caligrafia PT)
     * ou com caracteres adicionais.
     */
    public static function normalizeNumeric($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && preg_match('/-?\d+(?:[\.,]\d+)?/', $value, $matches)) {
            return (float) str_replace(',', '.', $matches[0]);
        }

        return null;
    }

    /**
     * Limpa e normaliza os campos do array de resposta do Gemini.
     */
    public static function normalizeResponse(array $data, string $type): array
    {
        switch ($type) {
            case 'pressao':
                foreach (['pressao_1', 'pressao_2'] as $k) {
                    if (isset($data[$k])) {
                        $data[$k] = self::normalizeNumeric($data[$k]);
                    }
                }
                break;

            case 'filtro_lavagem':
            case 'filtro_enxaguamento':
            case 'filtro_posicao_normal':
                if (isset($data['posicao_correta'])) {
                    $data['posicao_correta'] = filter_var($data['posicao_correta'], FILTER_VALIDATE_BOOLEAN);
                }
                break;

            case 'contador':
                if (isset($data['leitura'])) {
                    $data['leitura'] = self::normalizeNumeric($data['leitura']);
                }
                break;

            case 'tanque':
                if (isset($data['nivel'])) {
                    $val = self::normalizeNumeric($data['nivel']);
                    $data['nivel'] = $val !== null ? (int) round($val) : null;
                }
                break;

            case 'analise_ns':
            case 'analise_nossa':
                foreach (['ph', 'cloro_livre', 'cloro_total', 'temperatura'] as $k) {
                    if (isset($data[$k])) {
                        $data[$k] = self::normalizeNumeric($data[$k]);
                    }
                }
                if (isset($data['transparencia'])) {
                    $val = self::normalizeNumeric($data['transparencia']);
                    $data['transparencia'] = $val !== null ? (int) round($val) : null;
                }
                break;

            case 'turbidimetro':
                if (isset($data['turbidez_ntu'])) {
                    $data['turbidez_ntu'] = self::normalizeNumeric($data['turbidez_ntu']);
                }
                break;

            case 'rotulo':
                foreach (['concentracao', 'dose_recomendada'] as $k) {
                    if (isset($data[$k])) {
                        $data[$k] = self::normalizeNumeric($data[$k]);
                    }
                }
                break;

            case 'daily':
                foreach (['contador', 'pressao_1', 'pressao_2'] as $k) {
                    if (isset($data[$k])) {
                        $data[$k] = self::normalizeNumeric($data[$k]);
                    }
                }
                break;
        }

        if (isset($data['confidence'])) {
            $data['confidence'] = (float) $data['confidence'];
        }

        return $data;
    }
}
