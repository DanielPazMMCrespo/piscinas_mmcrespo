import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { campoDeId, deveAvancar, idDoCampoSeguinte } from '../../resources/js/avanco-campos.js';

describe('campoDeId', () => {
    it('reconhece cada campo e a piscina a que pertence', () => {
        assert.deepEqual(campoDeId('ns_ph_4'), { campo: 'ns_ph', sufixo: '4', indice: 0 });
        assert.deepEqual(campoDeId('ns_temperatura_12'), { campo: 'ns_temperatura', sufixo: '12', indice: 3 });
    });

    it('ignora campos que nao sao das analises', () => {
        assert.equal(campoDeId('contador_valor_4'), null);
        assert.equal(campoDeId('banhistas_4'), null);
        assert.equal(campoDeId(''), null);
        assert.equal(campoDeId(undefined), null);
    });
});

describe('idDoCampoSeguinte', () => {
    it('segue a ordem do formulario dentro da mesma piscina', () => {
        assert.equal(idDoCampoSeguinte('ns_ph_4'), 'ns_cloro_livre_4');
        assert.equal(idDoCampoSeguinte('ns_cloro_livre_4'), 'ns_cloro_total_4');
        assert.equal(idDoCampoSeguinte('ns_cloro_total_4'), 'ns_temperatura_4');
    });

    it('nao sai da piscina no ultimo campo', () => {
        assert.equal(
            idDoCampoSeguinte('ns_temperatura_4'),
            null,
            'Depois da temperatura vem outra piscina: o cursor nao salta para la sozinho.',
        );
    });

    /**
     * Se o sufixo fosse comparado por prefixo em vez de por inteiro, o campo da
     * piscina 4 mandava o cursor para a piscina 41.
     */
    it('nao confunde a piscina 4 com a 41', () => {
        assert.equal(idDoCampoSeguinte('ns_ph_41'), 'ns_cloro_livre_41');
    });
});

describe('deveAvancar', () => {
    // --- O defeito que nao avancava --------------------------------------

    it('avanca no pH escrito com uma decimal quando chega as duas', () => {
        // "7,4" tem dois digitos: com a regra antiga (>= 3 digitos) nunca
        // avancava, e o pH e o primeiro campo de todos.
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '7,42' }), true);
    });

    it('avanca na temperatura a uma decimal', () => {
        assert.equal(deveAvancar({ id: 'ns_temperatura_4', valor: '27,5' }), false, 'e o ultimo campo');
        assert.equal(deveAvancar({ id: 'ns_cloro_total_4', valor: '1,2' }), false, 'cloro usa duas decimais');
        assert.equal(deveAvancar({ id: 'ns_cloro_total_4', valor: '1,20' }), true);
    });

    it('avanca no cloro a duas decimais', () => {
        assert.equal(deveAvancar({ id: 'ns_cloro_livre_4', valor: '1,05' }), true);
    });

    // --- O defeito que roubava o cursor ---------------------------------

    it('nao avanca a meio de escrever a segunda decimal', () => {
        assert.equal(
            deveAvancar({ id: 'ns_ph_4', valor: '7,4' }),
            false,
            'Quem quer escrever 7,42 nao pode perder o cursor no 7,4.',
        );
    });

    it('nao avanca sem separador decimal', () => {
        // "100" no cloro e um erro de escrita a meio, nao um valor pronto.
        assert.equal(deveAvancar({ id: 'ns_cloro_livre_4', valor: '100' }), false);
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '742' }), false);
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '7' }), false);
    });

    it('nao avanca por um evento sintetico do normalizador de virgula', () => {
        // O normalizador dispara um `input` proprio ao trocar , por . Sem esta
        // guarda, o cursor saltava sem ninguem ter escrito nada.
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '7,42', confiavel: false }), false);
    });

    // --- Enter e sempre um pedido explicito -----------------------------

    it('avanca com Enter mesmo com o campo a meio', () => {
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '7,4', enter: true }), true);
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '7', enter: true }), true);
    });

    it('o Enter no ultimo campo nao salta para outra piscina', () => {
        assert.equal(deveAvancar({ id: 'ns_temperatura_4', valor: '27,5', enter: true }), false);
    });

    // --- Lixo -----------------------------------------------------------

    it('ignora campos que nao sao das analises', () => {
        assert.equal(deveAvancar({ id: 'contador_valor_4', valor: '1234,50' }), false);
        assert.equal(deveAvancar({ id: 'contador_valor_4', valor: '1234,50', enter: true }), false);
    });

    it('aguenta valores vazios e ponto sem decimais', () => {
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '' }), false);
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: null }), false);
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '7,' }), false);
        assert.equal(deveAvancar({ id: 'ns_ph_4', valor: '7,4a' }), false);
    });
});
