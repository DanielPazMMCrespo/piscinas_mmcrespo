<?php

declare(strict_types=1);

namespace App\Constants;

use Illuminate\Support\Str;

/**
 * Tradução de nomes de coluna e de valores para a coluna "Alterações" do
 * Activity Log. Sem isto o admin vê o `properties` cru do Spatie
 * (`{"old":{...},"attributes":{...}}`), que é ilegível.
 *
 * Um campo não mapeado cai no fallback `ucfirst(str_replace('_', ' '))` — a
 * lista abaixo é conveniência, não um requisito de cobertura total.
 */
final class AuditLabels
{
    /** @var array<string, string> */
    private const CAMPOS = [
        // Registo diário / análises
        'pool_id' => 'Piscina',
        'user_id' => 'Utilizador',
        'registado_em' => 'Registado em',
        'ph' => 'pH',
        'cloro_livre' => 'Cloro livre',
        'cloro_total' => 'Cloro total',
        'temperatura_agua' => 'Temp. água',
        'temperatura_ar' => 'Temp. ar',
        'transparencia' => 'Turbidez',
        'ns_ph' => 'pH (NS)',
        'ns_cloro_livre' => 'Cloro livre (NS)',
        'ns_cloro_total' => 'Cloro total (NS)',
        'ns_temperatura_agua' => 'Temp. água (NS)',
        'observacoes' => 'Observações',
        'e_correcao' => 'É correção',
        'corrige_registo_id' => 'Corrige registo',
        'razao_correcao' => 'Razão da correção',
        'agua_modo' => 'Estado da torneira',
        'contador' => 'Contador',
        'bomba_estado' => 'Estado da bomba',
        'tanque_estado' => 'Estado do tanque',

        // Piscinas / instalações
        'installation_id' => 'Instalação',
        'name' => 'Nome',
        'type' => 'Tipo',
        'volume' => 'Volume (m³)',
        'temp_min' => 'Temp. mínima',
        'temp_max' => 'Temp. máxima',
        'orp_min' => 'ORP mínimo',
        'orp_max' => 'ORP máximo',
        'active' => 'Ativa',

        // Encerramentos
        'inicio' => 'Início',
        'fim' => 'Fim',
        'motivo' => 'Motivo',
        'agua_em_tratamento' => 'Água em tratamento',
        'encerrada_por' => 'Encerrada por',
        'reaberta_por' => 'Reaberta por',
        'reaberta_em' => 'Reaberta em',

        // Incidentes
        'status' => 'Estado',
        'descricao' => 'Descrição',
        'severidade' => 'Severidade',
        'resolvido_em' => 'Resolvido em',
        'resolvido_por' => 'Resolvido por',
        'resolucao' => 'Resolução',
        'incident_id' => 'Incidente',
        'texto' => 'Mensagem',

        // Stock
        'product_id' => 'Produto',
        'quantity' => 'Quantidade',
        'limite_minimo' => 'Limite mínimo',
        'tipo_movimento' => 'Movimento',
        'fornecedor' => 'Fornecedor',
        'unidade' => 'Unidade',

        // Bidões
        'capacidade_ml' => 'Capacidade (ml)',
        'restante_ml' => 'Restante (ml)',
        'quantidade_ml' => 'Quantidade (ml)',
        'restante_apos_ml' => 'Restante após (ml)',
        'alerta_percent' => 'Alerta (%)',
        'reabastecido_em' => 'Reabastecido em',
        'reabastecido_por' => 'Reabastecido por',

        // Utilizadores / convites
        'email' => 'E-mail',
        'role' => 'Cargo',
        'pool_ids' => 'Piscinas atribuídas',
        'invited_by_id' => 'Convidado por',
        'accepted_at' => 'Aceite em',
        'expires_at' => 'Expira em',
        'notification_preferences' => 'Preferências de notificação',

        // Sensores
        'hanna_device_id' => 'Dispositivo Hanna',

        // Contexto de auditoria
        'ip' => 'IP',
        'ua' => 'Dispositivo',
    ];

    public static function campo(string $chave): string
    {
        return self::CAMPOS[$chave] ?? Str::of($chave)->replace('_', ' ')->ucfirst()->toString();
    }

    public static function valor(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '(vazio)';
        }

        if (is_bool($valor)) {
            return $valor ? 'Sim' : 'Não';
        }

        if (is_array($valor)) {
            return Str::limit(json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 120);
        }

        return Str::limit((string) $valor, 120);
    }
}
