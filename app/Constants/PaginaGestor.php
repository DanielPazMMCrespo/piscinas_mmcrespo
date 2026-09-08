<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Páginas que podem ser individualmente ligadas/desligadas por utilizador
 * Gestor (UserResource). Outros cargos não são afetados — só cobre páginas
 * já hoje acessíveis a Gestor; core (Registo Diário/Incidentes) e páginas
 * admin-only ficam sempre fora.
 */
final class PaginaGestor
{
    public const UTILIZADORES = 'utilizadores';

    public const CONVITES = 'convites';

    public const ENCERRAMENTOS = 'encerramentos';

    public const STOCK_VISAO_GERAL = 'stock_visao_geral';

    public const STOCK_ARMAZEM = 'stock_armazem';

    public const STOCK_INSTALACAO = 'stock_instalacao';

    public const PRODUTOS = 'produtos';

    public const BIDOES_DOSAGEM = 'bidoes_dosagem';

    public const MOVIMENTOS_ARMAZEM = 'movimentos_armazem';

    public const MOVIMENTOS_INSTALACAO = 'movimentos_instalacao';

    public const ANALISE_PARAMETROS = 'analise_parametros';

    public const RELATORIO_PDF = 'relatorio_pdf';

    public const ESQUEMA = 'esquema';

    private function __construct()
    {
        // This class cannot be instantiated
    }

    public static function all(): array
    {
        return [
            self::UTILIZADORES,
            self::CONVITES,
            self::ENCERRAMENTOS,
            self::STOCK_VISAO_GERAL,
            self::STOCK_ARMAZEM,
            self::STOCK_INSTALACAO,
            self::PRODUTOS,
            self::BIDOES_DOSAGEM,
            self::MOVIMENTOS_ARMAZEM,
            self::MOVIMENTOS_INSTALACAO,
            self::ANALISE_PARAMETROS,
            self::RELATORIO_PDF,
            self::ESQUEMA,
        ];
    }

    public static function labels(): array
    {
        return [
            self::UTILIZADORES => 'Utilizadores',
            self::CONVITES => 'Convites',
            self::ENCERRAMENTOS => 'Encerramentos',
            self::STOCK_VISAO_GERAL => 'Stock',
            self::STOCK_ARMAZEM => 'Stock Armazém',
            self::STOCK_INSTALACAO => 'Stock Instalação',
            self::PRODUTOS => 'Produtos',
            self::BIDOES_DOSAGEM => 'Bidões de Dosagem',
            self::MOVIMENTOS_ARMAZEM => 'Movimentos Armazém',
            self::MOVIMENTOS_INSTALACAO => 'Movimentos Instalação',
            self::ANALISE_PARAMETROS => 'Análise de Parâmetros',
            self::RELATORIO_PDF => 'Relatório PDF',
            self::ESQUEMA => 'Esquema',
        ];
    }
}
