/**
 * Decide se o cursor avanca para o campo seguinte das analises da agua.
 *
 * A versao anterior avancava quando o campo tinha 3 digitos, contados a ignorar
 * o separador decimal. Isso rouba o cursor a meio de escrever:
 *
 * - "100" no cloro (erro de escrita, o tecnico ia corrigir) avancava;
 * - "742" no pH (sem virgula ainda) avancava;
 * - e o normalizador de virgula dispara um evento `input` sintetico ao trocar
 *   , por . -- sem guarda, o cursor saltava sem ninguem ter escrito nada.
 *
 * A regra nova avanca em dois casos, os dois inequivocos:
 *
 * - o utilizador premiu Enter (pediu-o de forma explicita) -- e novo: antes o
 *   Enter submetia o formulario a meio das leituras;
 * - o valor esta completo para AQUELE campo: tem separador decimal e chegou ao
 *   numero de decimais que o campo usa.
 *
 * Nunca avanca sem separador decimal. Nota: "7,4" no pH tambem nao avanca, e
 * esta certo -- quem quer escrever 7,42 nao pode perder o cursor pelo caminho.
 */

/**
 * Ordem dos campos das analises da agua, e quantas decimais cada um usa.
 * A ordem e a do formulario; a precisao e a que o tecnico escreve de facto.
 */
export const CAMPOS_ANALISE = [
    { nome: 'ns_ph', decimais: 2 },
    { nome: 'ns_cloro_livre', decimais: 2 },
    { nome: 'ns_cloro_total', decimais: 2 },
    { nome: 'ns_temperatura', decimais: 1 },
];

/**
 * O campo a que este id pertence, e o seu sufixo (o id da piscina).
 *
 * Os ids sao "ns_ph_4", "ns_cloro_livre_4"... O sufixo tem de ser comparado por
 * inteiro: sem isso "ns_cloro_livre_4" tambem casava com "ns_cloro_total_41".
 *
 * @param {string} id
 * @returns {{campo: string, sufixo: string, indice: number}|null}
 */
export function campoDeId(id) {
    if (typeof id !== 'string') {
        return null;
    }

    for (let i = 0; i < CAMPOS_ANALISE.length; i++) {
        const prefixo = CAMPOS_ANALISE[i].nome + '_';

        if (id.startsWith(prefixo)) {
            return { campo: CAMPOS_ANALISE[i].nome, sufixo: id.slice(prefixo.length), indice: i };
        }
    }

    return null;
}

/**
 * O id do campo seguinte, ou null se este e o ultimo.
 *
 * @param {string} id
 * @returns {string|null}
 */
export function idDoCampoSeguinte(id) {
    const atual = campoDeId(id);

    if (atual === null) {
        return null;
    }

    const seguinte = CAMPOS_ANALISE[atual.indice + 1];

    return seguinte ? seguinte.nome + '_' + atual.sufixo : null;
}

/**
 * @param {object} opcoes
 * @param {string} opcoes.id       id do campo onde se esta a escrever
 * @param {string} opcoes.valor    o que esta escrito
 * @param {boolean} opcoes.enter   o utilizador premiu Enter
 * @param {boolean} opcoes.confiavel  o evento veio de uma tecla real, nao de um
 *                                    evento sintetico do normalizador
 * @returns {boolean}
 */
export function deveAvancar({ id, valor, enter = false, confiavel = true }) {
    const atual = campoDeId(id);

    if (atual === null || idDoCampoSeguinte(id) === null) {
        return false;
    }

    if (enter) {
        // Enter e um pedido explicito: avanca mesmo com o campo a meio.
        return true;
    }

    if (! confiavel) {
        return false;
    }

    const texto = String(valor ?? '').replace(',', '.');
    const separador = texto.indexOf('.');

    // Sem separador decimal o numero pode estar a meio de ser escrito.
    if (separador === -1) {
        return false;
    }

    const decimais = texto.slice(separador + 1);

    if (! /^\d+$/.test(decimais)) {
        return false;
    }

    return decimais.length >= CAMPOS_ANALISE[atual.indice].decimais;
}
