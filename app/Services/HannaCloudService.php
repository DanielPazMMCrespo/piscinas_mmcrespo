<?php

namespace App\Services;

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

    /**
     * Chave AES-256 (base64). Encontrada no JS público da Hanna Cloud webapp.
     * Não é secreta — está hardcoded no front-end deles.
     */
    private const AES_KEY_B64 = 'MzJmODBmMDU0ZTAyNDFjYWM0YTVhOGQxY2ZlZTkwMDM=';

    private ?string $accessToken = null;

    // ------------------------------------------------------------------ Auth

    /**
     * Autentica com email e password e guarda o access token.
     * Lança \RuntimeException em caso de falha.
     */
    public function authenticate(string $email, string $password): void
    {
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

        $params = $readings['messages']['parameters'] ?? [];

        return [
            'dt' => $readings['DT'] ?? null,
            'ph' => self::paramValue($params, ['pH', 'ph']),
            'orp' => self::paramValue($params, ['ORP', 'orp']),
            'temperatura_agua' => self::paramValue($params, ['WT', 'waterTemp', 'waterTemperature', 'TEMP', 'temp']),
            'temperatura_ar' => self::paramValue($params, ['AT', 'airTemp', 'airTemperature']),
            'caudal_ph' => self::paramValue($params, ['PHF', 'pHFlow', 'pH_flow']),
            'caudal_cloro' => self::paramValue($params, ['CLF', 'chlorineFlow', 'cl_flow']),
            'raw_parameters' => $params,
        ];
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

    // ---------------------------------------------------------------- Helpers

    /**
     * Encripta uma string com AES-256-CBC usando a chave pública da Hanna Cloud.
     * Resultado: "{iv_16chars}:{encrypted_hex}"
     */
    private function encrypt(string $plaintext): string
    {
        $key = base64_decode(self::AES_KEY_B64);
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
