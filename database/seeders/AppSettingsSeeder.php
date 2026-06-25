<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Limites Regulamentares
            [
                'key' => 'ph_min',
                'value' => 6.9,
                'group' => 'limites',
                'label' => 'pH Mínimo',
                'type' => 'number',
                'description' => 'Valor de pH mínimo aceitável (Recomendado CN 14/DA: 6.9)',
            ],
            [
                'key' => 'ph_max',
                'value' => 8.0,
                'group' => 'limites',
                'label' => 'pH Máximo',
                'type' => 'number',
                'description' => 'Valor de pH máximo aceitável (Recomendado CN 14/DA: 8.0)',
            ],
            [
                'key' => 'cloro_livre_min',
                'value' => 0.5,
                'group' => 'limites',
                'label' => 'Cloro Livre Mínimo (mg/L)',
                'type' => 'number',
                'description' => 'Concentração de cloro livre mínima aceitável em mg/L (Recomendado: 0.5)',
            ],
            [
                'key' => 'cloro_livre_max',
                'value' => 2.0,
                'group' => 'limites',
                'label' => 'Cloro Livre Máximo (mg/L)',
                'type' => 'number',
                'description' => 'Concentração de cloro livre máxima aceitável em mg/L (Recomendado: 2.0)',
            ],
            [
                'key' => 'cloro_combinado_max',
                'value' => 0.6,
                'group' => 'limites',
                'label' => 'Cloro Combinado Máximo (mg/L)',
                'type' => 'number',
                'description' => 'Concentração de cloro combinado máxima aceitável em mg/L (Recomendado: 0.6)',
            ],
            [
                'key' => 'transparencia_max',
                'value' => 5.0,
                'group' => 'limites',
                'label' => 'Turbidez Máxima (FNU)',
                'type' => 'number',
                'description' => 'Limite máximo de turbidez em FNU (Recomendado: 5.0)',
            ],
            [
                'key' => 'aviso_amarelo_margem',
                'value' => 10.0,
                'group' => 'limites',
                'label' => 'Margem de Aviso Amarelo (%)',
                'type' => 'number',
                'description' => 'Percentagem de margem para o semáforo de aviso amarelo (Recomendado: 10%)',
            ],

            // Opções de Dropdowns
            [
                'key' => 'tipos_incidente',
                'value' => [
                    'avaria_equipamento' => 'Avaria de Equipamento',
                    'fuga_agua' => 'Fuga de Água',
                    'qualidade_agua' => 'Problema de Qualidade da Água',
                    'outro' => 'Outro'
                ],
                'group' => 'opcoes',
                'label' => 'Tipos de Incidente',
                'type' => 'array',
                'description' => 'Chave e rótulo para os tipos de incidente',
            ],
            [
                'key' => 'modos_agua',
                'value' => [
                    'auto_com_agua' => 'Automático (com recirculação de água)',
                    'auto_sem_agua' => 'Automático (sem recirculação de água)',
                    'on_com_agua' => 'Ligado (com recirculação de água)',
                    'on_sem_agua' => 'Ligado (sem recirculação de água)',
                    'off' => 'Desligado'
                ],
                'group' => 'opcoes',
                'label' => 'Modos de Funcionamento da Água',
                'type' => 'array',
                'description' => 'Chave e rótulo para modos de água no registo diário',
            ],
            [
                'key' => 'tipos_operacao_filtro',
                'value' => [
                    'lavagem' => 'Lavagem',
                    'enxaguamento' => 'Enxaguamento',
                    'posicao_normal' => 'Posição Normal / Filtração'
                ],
                'group' => 'opcoes',
                'label' => 'Tipos de Operação do Filtro',
                'type' => 'array',
                'description' => 'Chave e rótulo para tipos de operação do filtro',
            ],

            // Alertas e Dashboard
            [
                'key' => 'sensor_stale_threshold',
                'value' => 15,
                'group' => 'operacional',
                'label' => 'Threshold Sem Leitura do Sensor (minutos)',
                'type' => 'number',
                'description' => 'Tempo máximo sem leituras do sensor antes de considerar inativo (stale)',
            ],
            [
                'key' => 'hora_escalacao_sem_registo',
                'value' => 12,
                'group' => 'operacional',
                'label' => 'Hora Limite para Alerta de Falta de Registo',
                'type' => 'number',
                'description' => 'Hora (0-23) a partir da qual a falta de registo diário hoje vira alerta vermelho',
            ],
            [
                'key' => 'lookback_incidentes',
                'value' => 30,
                'group' => 'operacional',
                'label' => 'Dias de Histórico de Incidentes no Kanban',
                'type' => 'number',
                'description' => 'Número de dias passados para pesquisar incidentes não resolvidos para o Kanban',
            ],
            [
                'key' => 'max_incidentes_kanban',
                'value' => 10,
                'group' => 'operacional',
                'label' => 'Limite de Incidentes no Kanban',
                'type' => 'number',
                'description' => 'Máximo de incidentes listados no Kanban do Dashboard',
            ],
            [
                'key' => 'dias_pruning_alert_states',
                'value' => 7,
                'group' => 'operacional',
                'label' => 'Dias para Pruning de Alert States',
                'type' => 'number',
                'description' => 'Número de dias após os quais estados de alerta antigos são limpos',
            ],

            // Polling e Cache
            [
                'key' => 'polling_painel_piscinas',
                'value' => 15,
                'group' => 'polling',
                'label' => 'Intervalo Polling Painel Piscinas (segundos)',
                'type' => 'number',
                'description' => 'Tempo de polling em segundos para atualizar o painel de piscinas',
            ],
            [
                'key' => 'polling_kanban',
                'value' => 60,
                'group' => 'polling',
                'label' => 'Intervalo Polling Quadro Kanban (segundos)',
                'type' => 'number',
                'description' => 'Tempo de polling em segundos para atualizar o Quadro Operacional',
            ],
            [
                'key' => 'cache_ttl_painel',
                'value' => 10,
                'group' => 'polling',
                'label' => 'Cache TTL Painel Piscinas (minutos)',
                'type' => 'number',
                'description' => 'Tempo de vida da cache para dados do painel em minutos',
            ],
            [
                'key' => 'cache_ttl_alertas',
                'value' => 5,
                'group' => 'polling',
                'label' => 'Cache TTL Alertas (minutos)',
                'type' => 'number',
                'description' => 'Tempo de vida da cache para o cálculo de alertas em minutos',
            ],

            // Uploads
            [
                'key' => 'max_fotos_analise',
                'value' => 5,
                'group' => 'uploads',
                'label' => 'Máximo Fotos Análise',
                'type' => 'number',
                'description' => 'Número máximo de fotos permitidas por registo diário',
            ],
            [
                'key' => 'max_filesize_analises',
                'value' => 5120,
                'group' => 'uploads',
                'label' => 'Tamanho Máximo Ficheiro Análises (KB)',
                'type' => 'number',
                'description' => 'Tamanho máximo do ficheiro de upload para fotos de análises em KB',
            ],
            [
                'key' => 'max_filesize_filtros',
                'value' => 10240,
                'group' => 'uploads',
                'label' => 'Tamanho Máximo Ficheiro Filtros (KB)',
                'type' => 'number',
                'description' => 'Tamanho máximo do ficheiro de upload para fotos de filtros em KB',
            ],
            // Templates de Email
            [
                'key' => 'email_convite_assunto',
                'value' => 'Convite — Piscinas MMCrespo',
                'group' => 'email',
                'label' => 'Assunto do Email de Convite',
                'type' => 'text',
                'description' => 'Assunto do email enviado ao convidar um utilizador.',
            ],
            [
                'key' => 'email_convite_mensagem',
                'value' => 'Foi convidado(a) para aceder à plataforma de gestão operacional das Piscinas de Leiria, Maceira e Caranguejeira desenvolvido pela MMCrespo. Clique no botão abaixo para completar o seu registo e ativar a conta:',
                'group' => 'email',
                'label' => 'Email de Convite',
                'type' => 'text',
                'description' => 'Obrigado por se registar na plataforma de gestão operacional das Piscinas de Leiria, Maceira e Caranguejeira desenvolvido pela MMCrespo. Clique no botão abaixo para completar o seu registo e ativar a conta.',
            ],
        ];

        foreach ($settings as $setting) {
            AppSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'group' => $setting['group'],
                    'label' => $setting['label'],
                    'type' => $setting['type'],
                    'description' => $setting['description'],
                ]
            );
        }
    }
}
