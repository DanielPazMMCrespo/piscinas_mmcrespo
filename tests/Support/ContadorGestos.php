<?php

declare(strict_types=1);

namespace Tests\Support;

use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Section;

/**
 * Conta os gestos que o técnico faz para registar um dia normal.
 *
 * Existe porque "o formulário está mais rápido" não é uma afirmação que se
 * possa fazer sem uma régua. Este contador é a régua: anda pelo schema real do
 * Filament e conta o que o dedo do técnico tem de fazer.
 *
 * Um GESTO é uma unidade de atrito para quem está de pé numa casa de máquinas:
 *
 * - abrir uma secção fechada que está no caminho de um campo diário .. 1
 * - focar um campo vazio que tem de ser preenchido todos os dias ..... 1
 * - escrever o valor nesse campo .................................... 1
 * - tocar num botão ................................................. 1
 *
 * Não se contam teclas uma a uma: escrever "7,4" conta 1, como "28,3".
 * Não se contam campos que já vêm com o valor certo por herança (bomba
 * ferrada, modo de água), porque num dia normal o técnico não lhes toca.
 *
 * Secções fechadas contam-se por ANTEPASSADO: um campo dentro de uma secção
 * fechada que está dentro de outra secção fechada custa dois gestos a
 * alcançar, e a mesma secção partilhada por dois campos só se abre uma vez.
 */
final class ContadorGestos
{
    /**
     * Campos que o técnico preenche TODOS OS DIAS, segundo a auditoria por
     * papel. Um campo desta lista custa 2 gestos (focar + escrever), mais 1
     * por cada secção fechada que o esconde.
     *
     * Os quatro `ns_*` são parâmetros legais CN 14/DA — medidos, não herdados.
     * `contador_valor` nunca pode ter defeito: o contador tem de avançar de
     * facto. `pressao_filtro` é a única leitura que diz "tenho de lavar hoje?".
     */
    public const CAMPOS_DIARIOS = [
        'contador_valor',
        'pressao_filtro',
        'ns_ph',
        'ns_cloro_livre',
        'ns_cloro_total',
        'ns_temperatura',
    ];

    /**
     * Gestos fixos antes de chegar ao formulário: abrir "Registos Diários",
     * tocar "Criar", abrir o select de instalação, escolher a instalação.
     */
    public const GESTOS_DE_ENTRADA = 4;

    /**
     * Gestos para gravar: "Gravar Registos" e depois "Confirmar e guardar" no
     * slide-over de resumo.
     */
    public const GESTOS_DE_GRAVACAO = 2;

    /**
     * @return array{
     *     total: int,
     *     entrada: int,
     *     gravacao: int,
     *     aberturas: int,
     *     campos_diarios: int,
     *     por_piscina: array<string, array{aberturas: int, campos: int, gestos: int, secoes_fechadas: array<int, string>, campos_encontrados: array<int, string>}>,
     * }
     */
    public static function contar(ComponentContainer $form, int $gestosDeGravacao = self::GESTOS_DE_GRAVACAO): array
    {
        $porPiscina = [];

        foreach (self::cartoesDePiscina($form) as $nome => $cartao) {
            $encontrados = [];
            $fechadasNoCaminho = [];

            self::percorrer($cartao, [], $encontrados, $fechadasNoCaminho);

            $porPiscina[$nome] = [
                'aberturas' => count($fechadasNoCaminho),
                'campos' => count($encontrados),
                'gestos' => count($fechadasNoCaminho) + (count($encontrados) * 2),
                'secoes_fechadas' => array_values($fechadasNoCaminho),
                'campos_encontrados' => array_keys($encontrados),
            ];
        }

        $aberturas = array_sum(array_column($porPiscina, 'aberturas'));
        $campos = array_sum(array_column($porPiscina, 'campos'));

        return [
            'total' => self::GESTOS_DE_ENTRADA + $aberturas + ($campos * 2) + $gestosDeGravacao,
            'entrada' => self::GESTOS_DE_ENTRADA,
            'gravacao' => $gestosDeGravacao,
            'aberturas' => $aberturas,
            'campos_diarios' => $campos,
            'por_piscina' => $porPiscina,
        ];
    }

    /**
     * Anda pela árvore a partir do cartão da piscina, arrastando a lista de
     * secções fechadas por que já passou. Quando encontra um campo diário,
     * marca-o e marca as secções fechadas que estão no caminho até ele —
     * secções fechadas que não escondem nada de diário não contam.
     *
     * @param  array<int, string>  $caminhoFechado  secções fechadas acima deste nó
     * @param  array<string, true>  $encontrados
     * @param  array<string, string>  $fechadasNoCaminho
     */
    private static function percorrer(
        Component $no,
        array $caminhoFechado,
        array &$encontrados,
        array &$fechadasNoCaminho,
    ): void {
        if ($no instanceof Field && in_array($no->getName(), self::CAMPOS_DIARIOS, true)) {
            $encontrados[$no->getName()] = true;

            foreach ($caminhoFechado as $chave) {
                $fechadasNoCaminho[$chave] = $chave;
            }
        }

        foreach ($no->getChildComponentContainers(withHidden: true) as $container) {
            foreach ($container->getComponents(withHidden: true) as $filho) {
                $caminhoDoFilho = $caminhoFechado;

                if ($filho instanceof Section && $filho->isCollapsible() && $filho->isCollapsed()) {
                    $caminhoDoFilho[] = self::rotulo($filho);
                }

                self::percorrer($filho, $caminhoDoFilho, $encontrados, $fechadasNoCaminho);
            }
        }
    }

    /**
     * Os cartões por piscina são as Sections cujo statePath acaba em
     * "pools.{id}". O Filament devolve o caminho absoluto ("data.pools.1"), e
     * as secções filhas herdam o mesmo caminho — por isso o cartão é o
     * primeiro (mais acima) com esse sufixo.
     *
     * @return array<string, Section>
     */
    private static function cartoesDePiscina(ComponentContainer $form): array
    {
        $cartoes = [];

        foreach ($form->getFlatComponents(withHidden: true) as $componente) {
            if (! $componente instanceof Section) {
                continue;
            }

            if (! preg_match('/(^|\.)pools\.\d+$/', $componente->getStatePath())) {
                continue;
            }

            // Só o cartão da piscina tem um cabeçalho com o nome dela; as
            // secções de dentro partilham o statePath mas não são cartões.
            $chave = self::rotulo($componente);

            if (! str_starts_with($chave, '🏊')) {
                continue;
            }

            $cartoes[$chave] = $componente;
        }

        return $cartoes;
    }

    private static function rotulo(Section $seccao): string
    {
        $heading = $seccao->getHeading();

        return is_string($heading) && $heading !== '' ? $heading : $seccao->getStatePath();
    }
}
