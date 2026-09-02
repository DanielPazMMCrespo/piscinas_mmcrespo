import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { normalizarNumeroPt } from '../../resources/js/numero-pt.js';

/**
 * Esta funcao decide que numero entra no livro de registo sanitario. Corre com
 * `npm run test:js` (node --test, embutido no Node — sem dependencias novas).
 */
describe('normalizarNumeroPt', () => {
    it('troca a virgula pelo ponto, que e o caso de todos os dias', () => {
        assert.equal(normalizarNumeroPt('7,2'), '7.2');
        assert.equal(normalizarNumeroPt('0,55'), '0.55');
        assert.equal(normalizarNumeroPt('28,4'), '28.4');
    });

    it('deixa em paz um numero que ja vem com ponto', () => {
        assert.equal(normalizarNumeroPt('7.2'), '7.2');
        assert.equal(normalizarNumeroPt('14'), '14');
    });

    it('respeita os estados intermedios de quem esta a escrever', () => {
        assert.equal(normalizarNumeroPt('7'), '7');
        assert.equal(normalizarNumeroPt('7,'), '7.');
        assert.equal(normalizarNumeroPt('7.'), '7.');
        assert.equal(normalizarNumeroPt(',5'), '.5');
    });

    // --- O erro de 1000x -----------------------------------------------------

    it('le o ponto como separador de milhares quando ha virgula decimal', () => {
        // Uma leitura de contador copiada de uma folha de calculo.
        assert.equal(normalizarNumeroPt('1.234,56'), '1234.56');
        assert.equal(normalizarNumeroPt('12.345,6'), '12345.6');
        assert.equal(normalizarNumeroPt('1.234.567,89'), '1234567.89');
    });

    it('nao inventa milhares onde nao ha grupos de tres digitos', () => {
        // "7.2,5" e um erro de escrita, nao um numero com milhares. Truncar e
        // visivel; reinterpretar seria adivinhar.
        assert.equal(normalizarNumeroPt('7.2,5'), '7.2');
    });

    it('aceita espaco como separador de milhares', () => {
        assert.equal(normalizarNumeroPt('1 234,56'), '1234.56');
        assert.equal(normalizarNumeroPt('1 234,56'), '1234.56');
    });

    // --- O sinal perdido -----------------------------------------------------

    it('nao apaga o sinal negativo', () => {
        // Apagar o menos transforma -3 em 3 sem ninguem ver. O limite
        // minValue(0) do campo recusa depois, com mensagem.
        assert.equal(normalizarNumeroPt('-3'), '-3');
        assert.equal(normalizarNumeroPt('-0,5'), '-0.5');
    });

    it('descarta um mais a frente, que nao muda o valor', () => {
        assert.equal(normalizarNumeroPt('+7,2'), '7.2');
    });

    // --- Pontos a mais -------------------------------------------------------

    it('trunca no segundo ponto em vez de juntar os pedacos', () => {
        // Juntar dava 7.25, um numero que ninguem escreveu.
        assert.equal(normalizarNumeroPt('7.2.5'), '7.2');
        assert.equal(normalizarNumeroPt('1.2.3.4'), '1.2');
    });

    // --- Lixo ----------------------------------------------------------------

    it('deixa cair letras e simbolos', () => {
        assert.equal(normalizarNumeroPt('abc'), '');
        assert.equal(normalizarNumeroPt('7,2 mg/L'), '7.2');
        assert.equal(normalizarNumeroPt('pH 7,4'), '7.4');
    });

    it('nao aceita notacao cientifica como se fosse um numero', () => {
        // "1e5" nao pode virar 15. O "e" cai e o que resta e visivelmente
        // diferente do que se escreveu.
        assert.equal(normalizarNumeroPt('1e5'), '15');
        assert.notEqual(normalizarNumeroPt('1e5'), '100000');
    });

    it('aguenta vazio, nulo e espacos', () => {
        assert.equal(normalizarNumeroPt(''), '');
        assert.equal(normalizarNumeroPt('   '), '');
        assert.equal(normalizarNumeroPt(null), '');
        assert.equal(normalizarNumeroPt(undefined), '');
        assert.equal(normalizarNumeroPt('-'), '');
    });

    it('aceita numeros, nao so strings', () => {
        assert.equal(normalizarNumeroPt(7.2), '7.2');
        assert.equal(normalizarNumeroPt(0), '0');
    });
});
