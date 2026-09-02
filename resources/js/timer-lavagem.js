/**
 * Decide o que fazer com um timer de retrolavagem guardado no localStorage.
 *
 * Existe como módulo próprio porque esta decisão controla uma barra fixa que
 * aparece em TODAS as páginas do painel. Quando estava errada, um timer
 * esquecido ficava em cima da topbar durante meia hora e trancava o botão da
 * sidebar — a app inteira ficava sem navegação em telemóvel.
 *
 * Três regras, e a razão de cada uma:
 *
 * 1. **A chave inclui o utilizador.** No portátil da instalação, o técnico
 *    seguinte via o timer do anterior.
 *
 * 2. **A janela de atraso é curta.** Era de 30 minutos. Um timer de
 *    retrolavagem dura 3 minutos e serve para dizer quando virar a válvula;
 *    passado isso já não tem função nenhuma. Cinco minutos de vermelho ainda
 *    informam ("passaste do tempo"); trinta são só uma barra encravada.
 *    É esta regra que resolve de facto o sintoma — o carimbo do rascunho (3)
 *    não resolve, porque o rascunho do formulário sobrevive ao abandono do
 *    registo e só desaparece 45 minutos depois.
 *
 * 3. **O carimbo do rascunho apanha o resto.** Um timer cujo rascunho já não
 *    existe (descartado, expirado, storage limpo) não tem dono e sai. Com
 *    folga desde o arranque, porque o autosave tem debounce.
 */

export const MMC_TIMER_PREFIXO = 'mmc_timer_';

/** Folga entre iniciar um timer e o autosave do rascunho gravar. */
export const MMC_TIMER_GRACA_MS = 15_000;

/** Quanto tempo um timer vencido continua a mostrar-se, em vermelho. */
export const MMC_TIMER_ATRASO_MAX_MS = 5 * 60_000;

export function chaveTimer(userId, statePath) {
    return MMC_TIMER_PREFIXO + String(userId) + '_' + statePath;
}

export function chaveRascunho(userId) {
    return 'daily_record_form_draft_' + String(userId);
}

/**
 * Extrai o statePath de uma chave de timer. Devolve null quando a chave
 * pertence a outro utilizador do mesmo browser.
 *
 * Aceita as chaves antigas (sem utilizador) que já estão gravadas nos
 * browsers da equipa, para o mecanismo de limpeza as poder apanhar uma vez em
 * vez de as deixar presas para sempre.
 */
export function statePathDaChave(chave, userId) {
    if (!chave.startsWith(MMC_TIMER_PREFIXO)) {
        return null;
    }

    const resto = chave.slice(MMC_TIMER_PREFIXO.length);
    const meu = String(userId) + '_';

    if (resto.startsWith(meu)) {
        return resto.slice(meu.length);
    }

    return resto.startsWith('pools.') || resto.startsWith('data.pools.') ? resto : null;
}

/**
 * @typedef {Object} Veredicto
 * @property {'ignorar'|'mostrar'|'limpar_orfao'|'limpar_expirado'} acao
 * @property {number} [restantes] segundos até ao fim (negativo se passou)
 */

/**
 * @param {object} opcoes
 * @param {unknown} opcoes.dados          conteúdo do localStorage, já em objeto
 * @param {number} opcoes.agora           Date.now()
 * @param {boolean} opcoes.rascunhoExiste o rascunho carimbado ainda está lá
 * @returns {Veredicto}
 */
export function avaliarTimer({ dados, agora, rascunhoExiste }) {
    if (!dados || typeof dados !== 'object') {
        return { acao: 'ignorar' };
    }

    if (!dados.isRunning || !dados.endTime) {
        return { acao: 'ignorar' };
    }

    const restantes = Math.round((dados.endTime - agora) / 1000);
    const atrasoMs = restantes < 0 ? -restantes * 1000 : 0;

    // Vencido há demasiado tempo. Anuncia-se uma vez e sai — é esta regra que
    // impede a barra de ficar encravada.
    if (atrasoMs > MMC_TIMER_ATRASO_MAX_MS) {
        return { acao: 'limpar_expirado', restantes };
    }

    // Sem rascunho, o registo nunca vai ser gravado e o timer não tem dono.
    const semCarimbo = dados.startedAt === null || dados.startedAt === undefined;
    const foraDaGraca = semCarimbo || (agora - dados.startedAt) > MMC_TIMER_GRACA_MS;

    if (!rascunhoExiste && foraDaGraca) {
        return { acao: 'limpar_orfao', restantes };
    }

    return { acao: 'mostrar', restantes };
}
