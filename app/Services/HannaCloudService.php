<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente PHP para a API GraphQL da Hanna Cloud.
 *
 * Baseado no reverse-engineering da biblioteca oficial Python hanna-cloud 0.0.7
 * (https://pypi.org/project/hanna-cloud/). A chave de encriptação está hardcoded
 * no JavaScript público da webapp hannacloud.com — não é uma chave privada.
 *
 * Endpoints:
 *   Auth:    POST https://www.hannacloud.com/api/auth    (GraphQL Login)
 *   Dados:   POST https://www.hannacloud.com/api/graphql (GraphQL queries)
 *
 * Parâmetros devolvidos pelo BL132:
 *   pH, ORP (mV), temperatura da água (°C), temperatura do ar (°C),
 *   caudal bomba pH (mL/h), caudal bomba cloro (mL/h).
 */
class HannaCloudService
{
    private const BASE_URL = 'https://www.hannacloud.com/api';

    private ?string $accessToken = null;

    // ------------------------------------------------------------------ Auth

    /**
     * Autentica com email e password e guarda o access token.
     * Lança \RuntimeException em caso de falha.
     */
    public function authenticate(string $email, string $password): void
    {
        $cachedToken = Cache::get('hanna_cloud_access_token');
        if ($cachedToken) {
            $this->accessToken = $cachedToken;

            return;
        }

        $query = <<<'GQL'
        query Login($email: String!, $password: String!,
                    $userLanguage: String!, $source: String) {
          login(email: $email password: $password
                language: $userLanguage source: $source) {
            token
            tokenType
          }
        }
        GQL;

        $payload = [
            'operationName' => 'Login',
            'variables' => [
                'email' => $this->encrypt($email),
                'password' => $this->encrypt($password),
                'userLanguage' => 'English',
                'source' => 'web',
            ],
            'query' => $query,
        ];

        $data = $this->post('auth', $payload);
        $tokens = $data['login'] ?? [];

        foreach ($tokens as $token) {
            if (($token['tokenType'] ?? '') === 'accessToken') {
                $this->accessToken = $token['token'];
                // Cachear por 1 hora (3600 segundos)
                Cache::put('hanna_cloud_access_token', $this->accessToken, 3600);

                return;
            }
        }

        throw new \RuntimeException('Hanna Cloud: token de acesso não encontrado na resposta de autenticação.');
    }

    // ---------------------------------------------------------- Device queries

    /**
     * Lista todos os dispositivos BL12x/BL13x associados à conta.
     */
    public function getDevices(): array
    {
        $query = <<<'GQL'
        query Devices($modelGroups: [String!], $deviceLogs: Boolean!) {
          devices(modelGroups: $modelGroups, deviceLogs: $deviceLogs) {
            _id DID DM modelGroup DT
            DINFO { deviceName userId emailId tankId tankName }
            reportedSettings status lastUpdated deviceName
          }
        }
        GQL;

        $data = $this->graphql('Devices', [
            'modelGroups' => ['BL12x', 'BL13x', 'BL13xs'],
            'deviceLogs' => true,
        ], $query);

        $devices = $data['devices'] ?? [];

        foreach ($devices as &$device) {
            $sy = $device['reportedSettings']['SY'] ?? '';
            $parts = explode(',', $sy);
            $device['manufacturer'] = $parts[0] ?? '';
            $device['serial_number'] = $parts[4] ?? '';
            $device['name'] = $device['DINFO']['deviceName'] ?? $device['DID'];
        }

        return $devices;
    }

    /**
     * Última leitura do sensor para um device ID.
     * Devolve array com: pH, orp, temperatura_agua, temperatura_ar,
     *                    caudal_ph, caudal_cloro, dt, raw_parameters.
     */
    public function getLastReading(string $deviceId): array
    {
        $query = <<<'GQL'
        query GetLastDeviceReading($deviceIds: [String!]) {
          lastDeviceReadings(deviceIds: $deviceIds) {
            DID DT
            messages
          }
        }
        GQL;

        $data = $this->graphql('GetLastDeviceReading', ['deviceIds' => [$deviceId]], $query);
        $readings = $data['lastDeviceReadings'][0] ?? null;

        if (empty($readings)) {
            throw new \RuntimeException("Hanna Cloud: nenhuma leitura para o dispositivo {$deviceId}.");
        }

        $messages = $readings['messages'] ?? [];
        $params = $messages['parameters'] ?? [];

        return [
            'dt' => $readings['DT'] ?? null,
            'ph' => self::paramValue($params, ['pH', 'ph']),
            'orp' => self::paramValue($params, ['ORP', 'orp']),
            'temperatura_agua' => self::paramValue($params, ['WT', 'waterTemp', 'waterTemperature', 'TEMP', 'temp']),
            'temperatura_ar' => self::paramValue($params, ['AT', 'airTemp', 'airTemperature']),
            'caudal_ph' => self::paramValue($params, ['acidBase', 'PHF', 'pHFlow', 'pH_flow']),
            'caudal_cloro' => self::paramValue($params, ['cl', 'CLF', 'chlorineFlow', 'cl_flow']),
            'raw_parameters' => $params,
            'alarms' => $messages['alarms'] ?? [],
            'warnings' => $messages['warnings'] ?? [],
            'errors' => $messages['errors'] ?? [],
            'status' => $messages['status'] ?? [],
        ];
    }

    /**
     * Definições completas de um dispositivo (inclui reportedSettings.DS —
     * setpoints/banda/overtime de dosagem — que a query de lista `devices()`
     * não devolve, só o cache reduzido com SY/GS).
     */
    public function getDeviceSettings(string $deviceId): array
    {
        $query = <<<'GQL'
        query getDeviceData($deviceId: String) {
          getBlDeviceData(deviceId: $deviceId) {
            DID DM modelGroup DT
            DINFO { deviceName userId emailId tankId tankName }
            reportedSettings
          }
        }
        GQL;

        $data = $this->graphql('getDeviceData', ['deviceId' => $deviceId], $query);

        return $data['getBlDeviceData'] ?? [];
    }

    /**
     * Histórico de leituras para um device entre duas datas.
     * Devolve o array `deviceLogHistory` tal como vem da API.
     */
    public function getHistory(string $deviceId, \DateTime $from, \DateTime $to): array
    {
        $query = <<<'GQL'
        query deviceLogHistory($deviceId: String!, $from: String!, $to: String!) {
          deviceLogHistory(deviceId: $deviceId, from: $from, to: $to) {
            data endDate startDate parameterNames
          }
        }
        GQL;

        $data = $this->graphql('deviceLogHistory', [
            'deviceId' => $deviceId,
            'from' => $from->format('c'),
            'to' => $to->format('c'),
        ], $query);

        return $data['deviceLogHistory'] ?? [];
    }

    /**
     * Histórico já normalizado: cada entrada do `deviceLogHistory.data` do BL13x
     * vem em CSV posicional. Devolve uma lista de leituras estruturadas ordenadas
     * da mais antiga para a mais recente.
     *
     * @return list<array{dt: ?string, ph: ?float, orp: ?float, temperatura_agua: ?float, temperatura_ar: ?float, dose_ph_ml: ?float, dose_cloro_ml: ?float, no_flow: bool}>
     */
    public function getHistoryReadings(string $deviceId, \DateTime $from, \DateTime $to): array
    {
        $history = $this->getHistory($deviceId, $from, $to);
        $entradas = $history['data'] ?? [];

        if (! is_array($entradas)) {
            return [];
        }

        $leituras = array_map([self::class, 'parseHistoryEntry'], $entradas);

        usort($leituras, static fn (array $a, array $b): int => ($a['dt'] ?? '') <=> ($b['dt'] ?? ''));

        return $leituras;
    }

    /**
     * Normaliza uma entrada bruta do `deviceLogHistory.data`.
     *
     * Formato posicional CSV do BL13x:
     *   RD = "pH,ORP,tempAgua,tempAr"       (leituras)
     *   DV = "dose_pH_mL,dose_cloro_mL"     (volume doseado nesse ciclo)
     *
     * @param  array<string, mixed>  $entry
     * @return array{dt: ?string, ph: ?float, orp: ?float, temperatura_agua: ?float, temperatura_ar: ?float, dose_ph_ml: ?float, dose_cloro_ml: ?float, no_flow: bool}
     */
    public static function parseHistoryEntry(array $entry): array
    {
        $rd = self::csvValores($entry['RD'] ?? null);
        $dv = self::csvValores($entry['DV'] ?? null);

        return [
            'dt' => isset($entry['DT']) ? (string) $entry['DT'] : null,
            'ph' => $rd[0] ?? null,
            'orp' => $rd[1] ?? null,
            'temperatura_agua' => $rd[2] ?? null,
            'temperatura_ar' => $rd[3] ?? null,
            'dose_ph_ml' => $dv[0] ?? null,
            'dose_cloro_ml' => $dv[1] ?? null,
            'no_flow' => (bool) ($entry['noFlow'] ?? false),
        ];
    }

    /**
     * Parte uma string CSV numérica ("7.28,767,29.82") num array de floats.
     * Campos vazios ficam null.
     *
     * @return array<int, ?float>
     */
    private static function csvValores(mixed $csv): array
    {
        if (! is_string($csv) || $csv === '') {
            return [];
        }

        return array_map(
            static fn (string $v): ?float => trim($v) === '' ? null : (float) $v,
            explode(',', $csv),
        );
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Converte o campo DT da API na hora real da leitura.
     *
     * A Hanna Cloud envia o relógio de parede do controlador mas etiqueta-o
     * como UTC ("2026-08-03T16:40:46.000Z"). Lido ao pé da letra, cada leitura
     * fica uma hora no futuro no verão português, e a validação de
     * plausibilidade do sync rejeita-a — foi o que parou as sondas todas.
     *
     * Os controladores estão em Portugal e mostram hora local, por isso o
     * relógio de parede é reancorado no fuso da aplicação, seja qual for o
     * offset que a API declare. Fonte única: não repetir este cálculo.
     */
    public static function horaLeitura(?string $dt): ?Carbon
    {
        if ($dt === null || trim($dt) === '') {
            return null;
        }

        $relogio = Carbon::parse($dt)->format('Y-m-d H:i:s');

        return Carbon::createFromFormat('Y-m-d H:i:s', $relogio, config('app.timezone'));
    }

    /**
     * Encripta uma string com AES-256-CBC usando a chave pública da Hanna Cloud.
     * Resultado: "{iv_16chars}:{encrypted_hex}"
     */
    private function encrypt(string $plaintext): string
    {
        $key = base64_decode(config('services.hanna.aes_key') ?: 'MzJmODBmMDU0ZTAyNDFjYWM0YTVhOGQxY2ZlZTkwMDM=');
        $iv = $this->randomAlphanumeric(16);

        $encrypted = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Hanna Cloud: falha na encriptação AES: '.openssl_error_string());
        }

        return $iv.':'.bin2hex($encrypted);
    }

    /** Gera uma string aleatória de caracteres alfanuméricos (letras + dígitos). */
    private function randomAlphanumeric(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $result;
    }

    /** Procura um valor nos `parameters` da API pelo nome (aceita vários aliases). */
    private static function paramValue(array $parameters, array $names): ?float
    {
        // parameters pode ser array de {name, value} ou dict associativo
        if (isset($parameters[0]) && is_array($parameters[0])) {
            // array de objectos
            foreach ($parameters as $p) {
                if (in_array($p['name'] ?? '', $names, true)) {
                    return isset($p['value']) ? (float) $p['value'] : null;
                }
            }
        } else {
            // dict associativo
            foreach ($names as $name) {
                if (isset($parameters[$name])) {
                    return (float) $parameters[$name];
                }
            }
        }

        return null;
    }

    /** POST para o endpoint de auth ou graphql. Devolve o campo `data` da resposta. */
    private function post(string $endpoint, array $payload): array
    {
        $headers = ['Accept' => '*/*', 'Content-Type' => 'application/json'];

        if ($this->accessToken) {
            $headers['Authorization'] = "Bearer {$this->accessToken}";
        }

        $response = Http::withHeaders($headers)
            ->timeout(10)
            ->post(self::BASE_URL.'/'.$endpoint, $payload);

        if ($response->status() === 403 && $this->accessToken) {
            // Token expirado — re-autentica e tenta uma vez mais (tratado fora).
            throw new \RuntimeException('Hanna Cloud: 403 — re-autenticação necessária.');
        }

        $response->throw(); // lança para outros 4xx/5xx

        $body = $response->json();

        if (isset($body['errors'])) {
            $msg = collect($body['errors'])->pluck('message')->implode('; ');
            throw new \RuntimeException("Hanna Cloud GraphQL error: {$msg}");
        }

        return $body['data'] ?? [];
    }

    /** Executa uma query GraphQL autenticada. Re-autentica automaticamente se o token expirar. */
    private function graphql(string $operationName, array $variables, string $query): array
    {
        if (! $this->accessToken) {
            throw new \RuntimeException('Hanna Cloud: não autenticado. Chama authenticate() primeiro.');
        }

        try {
            return $this->post('graphql', [
                'operationName' => $operationName,
                'variables' => $variables,
                'query' => $query,
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '403')) {
                // Invalidar o token em cache se recebermos 403
                Cache::forget('hanna_cloud_access_token');

                // Re-autentica com as credenciais da config.
                $this->authenticate(
                    config('services.hanna.email'),
                    config('services.hanna.password')
                );

                return $this->post('graphql', [
                    'operationName' => $operationName,
                    'variables' => $variables,
                    'query' => $query,
                ]);
            }
            throw $e;
        }
    }
}
