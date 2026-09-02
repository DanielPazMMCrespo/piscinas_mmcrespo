import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import {
    avaliarTimer,
    chaveRascunho,
    chaveTimer,
    MMC_TIMER_ATRASO_MAX_MS,
    MMC_TIMER_GRACA_MS,
    statePathDaChave,
} from '../../resources/js/timer-lavagem.js';

const AGORA = 1_700_000_000_000;

/** Timer a contar, iniciado ha muito tempo (fora da folga). */
function timerVivo(segundosRestantes) {
    return {
        isRunning: true,
        endTime: AGORA + segundosRestantes * 1000,
        startedAt: AGORA - 10 * 60_000,
        formKey: chaveRascunho(1),
    };
}

describe('chaves do timer', () => {
    it('inclui o utilizador, para o portatil da instalacao nao trocar timers', () => {
        assert.equal(
            chaveTimer(1, 'data.pools.3.timer_lavagem'),
            'mmc_timer_1_data.pools.3.timer_lavagem',
        );
    });

    it('devolve o statePath da propria chave', () => {
        assert.equal(
            statePathDaChave('mmc_timer_1_data.pools.3.timer_lavagem', 1),
            'data.pools.3.timer_lavagem',
        );
    });

    it('ignora a chave de outro utilizador', () => {
        assert.equal(statePathDaChave('mmc_timer_9_data.pools.3.timer_lavagem', 1), null);
    });

    it('reconhece uma chave antiga sem utilizador, para a poder limpar', () => {
        assert.equal(
            statePathDaChave('mmc_timer_pools.3.timer_lavagem', 1),
            'pools.3.timer_lavagem',
        );
    });

    it('ignora chaves que nao sao de timer', () => {
        assert.equal(statePathDaChave('daily_record_form_draft_1', 1), null);
    });
});

describe('avaliarTimer', () => {
    it('mostra um timer a contar com o rascunho vivo', () => {
        const v = avaliarTimer({ dados: timerVivo(100), agora: AGORA, rascunhoExiste: true });

        assert.equal(v.acao, 'mostrar');
        assert.equal(v.restantes, 100);
    });

    it('mostra um timer recem-vencido, em vermelho', () => {
        const v = avaliarTimer({ dados: timerVivo(-30), agora: AGORA, rascunhoExiste: true });

        assert.equal(v.acao, 'mostrar');
        assert.ok(v.restantes < 0, 'restantes negativo e o que pinta a barra de vermelho');
    });

    // --- A regra que resolve o sintoma ---------------------------------------

    it('limpa um timer vencido ha mais de cinco minutos, mesmo com rascunho vivo', () => {
        // Este e o caso do bug: o tecnico arranca o timer, sai sem gravar, e o
        // rascunho do formulario sobrevive ao abandono (so expira aos 45 min).
        // O carimbo do rascunho nao apanha isto -- a janela de atraso apanha.
        const atrasado = timerVivo(-(MMC_TIMER_ATRASO_MAX_MS / 1000) - 60);
        const v = avaliarTimer({ dados: atrasado, agora: AGORA, rascunhoExiste: true });

        assert.equal(v.acao, 'limpar_expirado');
    });

    it('mantem um timer vencido dentro da janela de cinco minutos', () => {
        const quaseFora = timerVivo(-(MMC_TIMER_ATRASO_MAX_MS / 1000) + 30);
        const v = avaliarTimer({ dados: quaseFora, agora: AGORA, rascunhoExiste: true });

        assert.equal(v.acao, 'mostrar');
    });

    it('a janela antiga de 30 minutos ja nao mostra nada', () => {
        // Guarda contra alguem repor o valor antigo: aos 20 min tem de sair.
        const v = avaliarTimer({ dados: timerVivo(-20 * 60), agora: AGORA, rascunhoExiste: true });

        assert.notEqual(v.acao, 'mostrar');
    });

    // --- Orfao ---------------------------------------------------------------

    it('limpa um timer cujo rascunho desapareceu', () => {
        const v = avaliarTimer({ dados: timerVivo(100), agora: AGORA, rascunhoExiste: false });

        assert.equal(v.acao, 'limpar_orfao');
    });

    it('nao mata um timer iniciado agora, antes do autosave gravar', () => {
        const acabadoDeArrancar = { ...timerVivo(170), startedAt: AGORA - 2000 };
        const v = avaliarTimer({ dados: acabadoDeArrancar, agora: AGORA, rascunhoExiste: false });

        assert.equal(v.acao, 'mostrar', 'a folga existe para isto');
    });

    it('a folga acaba', () => {
        const dados = { ...timerVivo(170), startedAt: AGORA - MMC_TIMER_GRACA_MS - 1000 };
        const v = avaliarTimer({ dados, agora: AGORA, rascunhoExiste: false });

        assert.equal(v.acao, 'limpar_orfao');
    });

    it('uma chave antiga sem carimbo e orfa quando nao ha rascunho', () => {
        const antigo = { isRunning: true, endTime: AGORA + 100_000 };
        const v = avaliarTimer({ dados: antigo, agora: AGORA, rascunhoExiste: false });

        assert.equal(v.acao, 'limpar_orfao');
    });

    // --- Nada a fazer --------------------------------------------------------

    it('ignora um timer em pausa', () => {
        const pausado = { ...timerVivo(100), isRunning: false };

        assert.equal(avaliarTimer({ dados: pausado, agora: AGORA, rascunhoExiste: true }).acao, 'ignorar');
    });

    it('ignora dados em falta ou corrompidos', () => {
        for (const dados of [null, undefined, {}, 'lixo', { isRunning: true }]) {
            assert.equal(avaliarTimer({ dados, agora: AGORA, rascunhoExiste: true }).acao, 'ignorar');
        }
    });
});
