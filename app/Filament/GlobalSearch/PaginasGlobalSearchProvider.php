<?php

declare(strict_types=1);

namespace App\Filament\GlobalSearch;

use Filament\Facades\Filament;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Support\Str;

/**
 * O Filament só pesquisa Resources; as páginas standalone (Dashboard, Relatório
 * PDF, Esquema, Definições...) nunca apareciam. Este provider mantém tudo o que
 * o DefaultGlobalSearchProvider faz e acrescenta um grupo "Páginas" no topo.
 *
 * Registado em AdminPanelProvider::globalSearch().
 */
class PaginasGlobalSearchProvider implements GlobalSearchProvider
{
    private const LIMITE_PAGINAS = 5;

    /**
     * Sinónimos por página. Quem pesquisa escreve o que quer fazer ("limites",
     * "livro sanitário", "sonda"), não o nome que a página tem na sidebar.
     *
     * Chave = slug da página (getSlug()), para não acoplar a nomes de classe.
     *
     * @var array<string, array<string>>
     */
    private const SINONIMOS = [
        'dashboard' => ['painel', 'inicio', 'alertas', 'conformidade', 'piscinas'],
        'analise-parametros' => ['graficos', 'historico', 'score', 'heatmap', 'consumo', 'ph', 'cloro'],
        'relatorio-pdf' => ['livro sanitario', 'livro de registo', 'cn14', 'cn 14', 'da', 'dgs', 'auditoria', 'exportar', 'imprimir'],
        'esquema' => ['circuito', 'agua', 'bomba', 'filtro', 'torneira', 'contador', 'sonda', 'hanna', 'bidoes'],
        'encerramentos' => ['fechar', 'fechada', 'reabrir', 'epoca balnear', 'manutencao', 'obra'],
        'definicoes' => ['configuracao', 'limites', 'legais', 'notificacoes', 'push', 'avisos', 'prazos', 'tolerancia'],
        'stock' => ['armazem', 'produtos', 'quimicos', 'visao geral'],
    ];

    public function getResults(string $query): ?GlobalSearchResults
    {
        $builder = GlobalSearchResults::make();

        $paginas = $this->pesquisarPaginas($query);

        if ($paginas !== []) {
            $builder->category('Páginas', $paginas);
        }

        foreach (Filament::getResources() as $resource) {
            if (! $resource::canGloballySearch()) {
                continue;
            }

            $resultados = $resource::getGlobalSearchResults($query);

            if (! $resultados->count()) {
                continue;
            }

            $builder->category($resource::getPluralModelLabel(), $resultados);
        }

        return $builder;
    }

    /** @return array<GlobalSearchResult> */
    private function pesquisarPaginas(string $query): array
    {
        $termo = $this->normalizar($query);

        if ($termo === '') {
            return [];
        }

        $resultados = [];

        foreach (Filament::getPages() as $pagina) {
            // shouldRegisterNavigation() exclui as páginas de autenticação, que
            // o discoverPages também apanha mas não são destinos de navegação.
            if (! $pagina::shouldRegisterNavigation() || ! $pagina::canAccess()) {
                continue;
            }

            $titulo = $pagina::getNavigationLabel();
            $grupo = $pagina::getNavigationGroup();

            $alvo = $this->normalizar(implode(' ', array_merge(
                [$titulo, (string) $grupo],
                self::SINONIMOS[$pagina::getSlug()] ?? [],
            )));

            if (! str_contains($alvo, $termo)) {
                continue;
            }

            $resultados[] = new GlobalSearchResult(
                title: $titulo,
                url: $pagina::getUrl(),
                details: $grupo !== null ? ['Secção' => $grupo] : [],
            );

            if (count($resultados) >= self::LIMITE_PAGINAS) {
                break;
            }
        }

        return $resultados;
    }

    /** Minúsculas sem acentos: "definicoes" tem de encontrar "Definições". */
    private function normalizar(string $valor): string
    {
        return trim(Str::lower(Str::ascii($valor)));
    }
}
