<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Constantes e catálogo de trabalhos para Paragens Técnicas (PoolClosureTask).
 *
 * O template de trabalhos é mantido em código (contrato legal imutável em runtime).
 */
final class TrabalhoParagem
{
    public const ESVAZIAMENTO_TANQUE = 'esvaziamento_tanque';

    public const LIMPEZA_TANQUE = 'limpeza_tanque';

    public const LIMPEZA_TANQUE_COMPENSACAO = 'limpeza_tanque_compensacao';

    public const LIMPEZA_CALEIRAS = 'limpeza_caleiras';

    public const MANUTENCAO_FILTROS = 'manutencao_filtros';

    public const LIMPEZA_CIRCUITO = 'limpeza_circuito';

    public const DESINFECAO_LEGIONELLA = 'desinfecao_legionella';

    public const ENCHIMENTO_TANQUE = 'enchimento_tanque';

    public const SUPERCLORACAO = 'supercloracao';

    public const REPOSICAO_CLORO = 'reposicao_cloro';

    public const ARRANQUE_AQUECIMENTO = 'arranque_aquecimento';

    public const VERIFICACAO_PARAMETROS = 'verificacao_parametros';

    public const OUTRO = 'outro';

    // Estados de execução
    public const ESTADO_PREVISTO = 'previsto';

    public const ESTADO_EM_CURSO = 'em_curso';

    public const ESTADO_EXECUTADO = 'executado';

    public const ESTADO_NAO_EXECUTADO = 'nao_executado';

    public const ESTADO_NAO_APLICAVEL = 'nao_aplicavel';

    // Origem do registo
    public const ORIGEM_DECLARADA = 'declarada';

    public const ORIGEM_RECONSTRUIDA = 'reconstruida';

    public const ORIGEM_INFERIDA = 'inferida';

    private function __construct()
    {
        // Classe utilitária pura
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ESVAZIAMENTO_TANQUE,
            self::LIMPEZA_TANQUE,
            self::LIMPEZA_TANQUE_COMPENSACAO,
            self::LIMPEZA_CALEIRAS,
            self::MANUTENCAO_FILTROS,
            self::LIMPEZA_CIRCUITO,
            self::DESINFECAO_LEGIONELLA,
            self::ENCHIMENTO_TANQUE,
            self::SUPERCLORACAO,
            self::REPOSICAO_CLORO,
            self::ARRANQUE_AQUECIMENTO,
            self::VERIFICACAO_PARAMETROS,
            self::OUTRO,
        ];
    }

    public static function isValid(string $tipo): bool
    {
        return in_array($tipo, self::all(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ESVAZIAMENTO_TANQUE => 'Esvaziamento do tanque',
            self::LIMPEZA_TANQUE => 'Limpeza e desinfeção do tanque',
            self::LIMPEZA_TANQUE_COMPENSACAO => 'Limpeza do tanque de compensação',
            self::LIMPEZA_CALEIRAS => 'Limpeza de caleiras e grelhas',
            self::MANUTENCAO_FILTROS => 'Manutenção / massa filtrante',
            self::LIMPEZA_CIRCUITO => 'Limpeza do circuito hidráulico',
            self::DESINFECAO_LEGIONELLA => 'Desinfeção e controlo de Legionella',
            self::ENCHIMENTO_TANQUE => 'Enchimento do tanque',
            self::SUPERCLORACAO => 'Supercloração / choque de arranque',
            self::REPOSICAO_CLORO => 'Reposição dos níveis de cloro',
            self::ARRANQUE_AQUECIMENTO => 'Arranque do sistema de aquecimento',
            self::VERIFICACAO_PARAMETROS => 'Verificação de parâmetros pré-reabertura',
            self::OUTRO => 'Outro trabalho',
        ];
    }

    public static function label(?string $tipo): string
    {
        if ($tipo === null) {
            return '—';
        }

        return self::labels()[$tipo] ?? $tipo;
    }

    /**
     * @return array<int, string>
     */
    public static function estados(): array
    {
        return [
            self::ESTADO_PREVISTO,
            self::ESTADO_EM_CURSO,
            self::ESTADO_EXECUTADO,
            self::ESTADO_NAO_EXECUTADO,
            self::ESTADO_NAO_APLICAVEL,
        ];
    }

    public static function isValidEstado(string $estado): bool
    {
        return in_array($estado, self::estados(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function estadoLabels(): array
    {
        return [
            self::ESTADO_PREVISTO => 'Previsto',
            self::ESTADO_EM_CURSO => 'Em curso',
            self::ESTADO_EXECUTADO => 'Executado',
            self::ESTADO_NAO_EXECUTADO => 'Não executado',
            self::ESTADO_NAO_APLICAVEL => 'Não aplicável',
        ];
    }

    public static function estadoLabel(?string $estado): string
    {
        if ($estado === null) {
            return '—';
        }

        return self::estadoLabels()[$estado] ?? $estado;
    }

    /**
     * @return array<int, string>
     */
    public static function origens(): array
    {
        return [
            self::ORIGEM_DECLARADA,
            self::ORIGEM_RECONSTRUIDA,
            self::ORIGEM_INFERIDA,
        ];
    }

    public static function isValidOrigem(string $origem): bool
    {
        return in_array($origem, self::origens(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function origemLabels(): array
    {
        return [
            self::ORIGEM_DECLARADA => 'Declarada',
            self::ORIGEM_RECONSTRUIDA => 'Reconstruída',
            self::ORIGEM_INFERIDA => 'Inferida',
        ];
    }

    public static function origemLabel(?string $origem): string
    {
        if ($origem === null) {
            return '—';
        }

        return self::origemLabels()[$origem] ?? $origem;
    }

    /**
     * Tipos de trabalho que são obrigações legais no plano de paragem.
     *
     * @return array<int, string>
     */
    public static function obrigatorios(): array
    {
        return [
            self::LIMPEZA_TANQUE,
            self::LIMPEZA_TANQUE_COMPENSACAO,
            self::DESINFECAO_LEGIONELLA,
            self::SUPERCLORACAO,
            self::REPOSICAO_CLORO,
            self::VERIFICACAO_PARAMETROS,
        ];
    }

    public static function isObrigatorio(string $tipo): bool
    {
        return in_array($tipo, self::obrigatorios(), true);
    }

    /**
     * Template base de 13 trabalhos de paragem técnica.
     *
     * @return array<int, array{ordem: int, tipo: string, obrigatorio: bool}>
     */
    public static function template(): array
    {
        return [
            ['ordem' => 1, 'tipo' => self::ESVAZIAMENTO_TANQUE, 'obrigatorio' => false],
            ['ordem' => 2, 'tipo' => self::LIMPEZA_TANQUE, 'obrigatorio' => true],
            ['ordem' => 3, 'tipo' => self::LIMPEZA_TANQUE_COMPENSACAO, 'obrigatorio' => true],
            ['ordem' => 4, 'tipo' => self::LIMPEZA_CALEIRAS, 'obrigatorio' => false],
            ['ordem' => 5, 'tipo' => self::MANUTENCAO_FILTROS, 'obrigatorio' => false],
            ['ordem' => 6, 'tipo' => self::LIMPEZA_CIRCUITO, 'obrigatorio' => false],
            ['ordem' => 7, 'tipo' => self::DESINFECAO_LEGIONELLA, 'obrigatorio' => true],
            ['ordem' => 8, 'tipo' => self::ENCHIMENTO_TANQUE, 'obrigatorio' => false],
            ['ordem' => 9, 'tipo' => self::SUPERCLORACAO, 'obrigatorio' => true],
            ['ordem' => 10, 'tipo' => self::REPOSICAO_CLORO, 'obrigatorio' => true],
            ['ordem' => 11, 'tipo' => self::ARRANQUE_AQUECIMENTO, 'obrigatorio' => false],
            ['ordem' => 12, 'tipo' => self::VERIFICACAO_PARAMETROS, 'obrigatorio' => true],
            ['ordem' => 13, 'tipo' => self::OUTRO, 'obrigatorio' => false],
        ];
    }

    /**
     * Mapeamento de tipos de OperationalAction compatíveis com cada trabalho de paragem.
     *
     * @return array<int, string>
     */
    public static function acoesOperacionaisCompativeis(string $tipo): array
    {
        return match ($tipo) {
            self::SUPERCLORACAO => ['tratamento_choque'],
            self::MANUTENCAO_FILTROS => ['lavagem_filtro', 'enxaguamento_filtro', 'manutencao_equipamento'],
            self::LIMPEZA_TANQUE, self::LIMPEZA_TANQUE_COMPENSACAO => ['tanque', 'aspiracao_fundo'],
            self::LIMPEZA_CALEIRAS => ['limpeza_praias'],
            self::REPOSICAO_CLORO, self::VERIFICACAO_PARAMETROS => ['analise_pontual'],
            self::ENCHIMENTO_TANQUE => ['contador', 'torneira'],
            default => [],
        };
    }
}
