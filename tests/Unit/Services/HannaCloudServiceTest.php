<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HannaCloudService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HannaCloudServiceTest extends TestCase
{
    private HannaCloudService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new HannaCloudService;
        config([
            'services.hanna.email' => 'test@mmcrespo.pt',
            'services.hanna.password' => 'secret_password',
            'services.hanna.aes_key' => base64_encode('12345678901234567890123456789012'), // 32-byte key
        ]);
    }

    public function test_authenticate_success(): void
    {
        Http::fake([
            'https://www.hannacloud.com/api/auth' => Http::response([
                'data' => [
                    'login' => [
                        [
                            'tokenType' => 'accessToken',
                            'token' => 'mock_access_token_123',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->service->authenticate('test@mmcrespo.pt', 'secret_password');

        $this->assertSame('mock_access_token_123', Cache::get('hanna_cloud_access_token'));
    }

    public function test_authenticate_throws_exception_on_missing_token(): void
    {
        Http::fake([
            'https://www.hannacloud.com/api/auth' => Http::response([
                'data' => [
                    'login' => [],
                ],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hanna Cloud: token de acesso não encontrado');

        $this->service->authenticate('test@mmcrespo.pt', 'secret_password');
    }

    public function test_get_devices(): void
    {
        Cache::put('hanna_cloud_access_token', 'mock_access_token_123', 3600);
        $this->service->authenticate('test@mmcrespo.pt', 'secret_password');

        Http::fake([
            'https://www.hannacloud.com/api/graphql' => Http::response([
                'data' => [
                    'devices' => [
                        [
                            '_id' => '1',
                            'DID' => 'DID-001',
                            'DM' => 'BL132',
                            'modelGroup' => 'BL13x',
                            'DT' => '2026-07-13T10:00:00Z',
                            'DINFO' => [
                                'deviceName' => 'Piscina A',
                                'userId' => 'user-1',
                                'emailId' => 'test@mmcrespo.pt',
                                'tankId' => 'tank-1',
                                'tankName' => 'Tank A',
                            ],
                            'reportedSettings' => [
                                'SY' => 'Hanna,BL132,1.0,2.0,SER-12345',
                            ],
                            'status' => 'online',
                            'lastUpdated' => '2026-07-13T10:00:00Z',
                            'deviceName' => 'Piscina A',
                        ],
                    ],
                ],
            ]),
        ]);

        $devices = $this->service->getDevices();

        $this->assertCount(1, $devices);
        $this->assertSame('Hanna', $devices[0]['manufacturer']);
        $this->assertSame('SER-12345', $devices[0]['serial_number']);
        $this->assertSame('Piscina A', $devices[0]['name']);

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            return $request->url() === 'https://www.hannacloud.com/api/graphql'
                && $payload['operationName'] === 'Devices'
                && str_contains($payload['query'], 'query Devices');
        });
    }

    public function test_get_last_reading(): void
    {
        Cache::put('hanna_cloud_access_token', 'mock_access_token_123', 3600);
        $this->service->authenticate('test@mmcrespo.pt', 'secret_password');

        Http::fake([
            'https://www.hannacloud.com/api/graphql' => Http::response([
                'data' => [
                    'lastDeviceReadings' => [
                        [
                            'DID' => 'DID-001',
                            'DT' => '2026-07-13T10:00:00Z',
                            'messages' => [
                                'parameters' => [
                                    ['name' => 'pH', 'value' => 7.25],
                                    ['name' => 'ORP', 'value' => 710],
                                    ['name' => 'waterTemp', 'value' => 26.5],
                                    ['name' => 'airTemp', 'value' => 24.0],
                                    ['name' => 'pHFlow', 'value' => 1.5],
                                    ['name' => 'chlorineFlow', 'value' => 0.9],
                                ],
                                'alarms' => ['pH high alarm'],
                                'warnings' => [],
                                'errors' => [],
                                'status' => [],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $reading = $this->service->getLastReading('DID-001');

        $this->assertSame('2026-07-13T10:00:00Z', $reading['dt']);
        $this->assertSame(7.25, $reading['ph']);
        $this->assertSame(710.0, $reading['orp']);
        $this->assertSame(26.5, $reading['temperatura_agua']);
        $this->assertSame(24.0, $reading['temperatura_ar']);
        $this->assertSame(1.5, $reading['caudal_ph']);
        $this->assertSame(0.9, $reading['caudal_cloro']);
        $this->assertSame(['pH high alarm'], $reading['alarms']);
    }

    public function test_get_device_settings(): void
    {
        Cache::put('hanna_cloud_access_token', 'mock_access_token_123', 3600);
        $this->service->authenticate('test@mmcrespo.pt', 'secret_password');

        Http::fake([
            'https://www.hannacloud.com/api/graphql' => Http::response([
                'data' => [
                    'getBlDeviceData' => [
                        'DID' => 'DID-001',
                        'reportedSettings' => [
                            'DS' => 'Auto,7.2,0.5,60,750,50,60,1.2,0.8,300,300',
                        ],
                    ],
                ],
            ]),
        ]);

        $settings = $this->service->getDeviceSettings('DID-001');

        $this->assertSame('DID-001', $settings['DID']);
        $this->assertSame('Auto,7.2,0.5,60,750,50,60,1.2,0.8,300,300', $settings['reportedSettings']['DS']);
    }

    public function test_get_history(): void
    {
        Cache::put('hanna_cloud_access_token', 'mock_access_token_123', 3600);
        $this->service->authenticate('test@mmcrespo.pt', 'secret_password');

        Http::fake([
            'https://www.hannacloud.com/api/graphql' => Http::response([
                'data' => [
                    'deviceLogHistory' => [
                        'data' => 'history_data_array',
                    ],
                ],
            ]),
        ]);

        $from = new \DateTime('2026-07-13 00:00:00');
        $to = new \DateTime('2026-07-13 23:59:59');

        $history = $this->service->getHistory('DID-001', $from, $to);

        $this->assertSame('history_data_array', $history['data']);
    }

    public function test_parse_history_entry_maps_positional_csv(): void
    {
        $parsed = HannaCloudService::parseHistoryEntry([
            'DT' => '2026-07-21 07:55:57',
            'RD' => '7.28,767,29.82,-44.5',
            'DV' => '0.00,74.76',
            'EV' => '00000000,00000000,00000000',
            'noFlow' => false,
        ]);

        $this->assertSame('2026-07-21 07:55:57', $parsed['dt']);
        $this->assertSame(7.28, $parsed['ph']);
        $this->assertSame(767.0, $parsed['orp']);
        $this->assertSame(29.82, $parsed['temperatura_agua']);
        $this->assertSame(-44.5, $parsed['temperatura_ar']);
        $this->assertSame(0.0, $parsed['dose_ph_ml']);
        $this->assertSame(74.76, $parsed['dose_cloro_ml']);
        $this->assertFalse($parsed['no_flow']);
    }

    public function test_parse_history_entry_tolerates_missing_fields(): void
    {
        $parsed = HannaCloudService::parseHistoryEntry(['DT' => '2026-07-21 07:00:00']);

        $this->assertNull($parsed['ph']);
        $this->assertNull($parsed['dose_cloro_ml']);
        $this->assertFalse($parsed['no_flow']);
    }

    public function test_get_history_readings_sorts_oldest_first(): void
    {
        Cache::put('hanna_cloud_access_token', 'mock_access_token_123', 3600);
        $this->service->authenticate('test@mmcrespo.pt', 'secret_password');

        Http::fake([
            'https://www.hannacloud.com/api/graphql' => Http::response([
                'data' => [
                    'deviceLogHistory' => [
                        'data' => [
                            ['DT' => '2026-07-21 07:55:57', 'RD' => '7.28,767,29.82,-44.5', 'DV' => '0.00,74.76'],
                            ['DT' => '2026-07-21 07:39:27', 'RD' => '7.27,766,29.80,-44.5', 'DV' => '0.00,75.34'],
                        ],
                    ],
                ],
            ]),
        ]);

        $leituras = $this->service->getHistoryReadings(
            'DID-001',
            new \DateTime('2026-07-21 07:00:00'),
            new \DateTime('2026-07-21 08:00:00'),
        );

        $this->assertCount(2, $leituras);
        $this->assertSame('2026-07-21 07:39:27', $leituras[0]['dt']);
        $this->assertSame('2026-07-21 07:55:57', $leituras[1]['dt']);
        $this->assertSame(0.0, $leituras[0]['dose_ph_ml']);
        $this->assertSame(75.34, $leituras[0]['dose_cloro_ml']);
    }
}
