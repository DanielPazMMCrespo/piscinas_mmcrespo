/**
 * Normalização de números escritos à portuguesa para a forma que o Livewire e
 * o PHP entendem (ponto decimal).
 *
 * Existe como módulo próprio porque decide que número entra no livro de
 * registo sanitário. Uma função pura é a única forma de a provar com testes
 * (`npm run test:js`), e a versão anterior vivia solta dentro de um listener
 * no app.js, onde não se podia testar nada.
 *
 * Três coisas que a versão anterior fazia mal, todas em silêncio:
 *
 * 1. `1.234,56` dava `1.23456`. O ponto era tratado como decimal e a vírgula
 *    juntava-se-lhe, num erro de 1000x. É o valor realista de uma leitura de
 *    contador de água copiada de uma folha de cálculo.
 * 2. `-3` dava `3`. O sinal era removido pelo filtro de caracteres, e um valor
 *    negativo passava a positivo sem ninguém ver.
 * 3. `7.2.5` dava `7.25`. Juntar os pedaços inventa um número que ninguém
 *    escreveu. Truncar é visível; juntar não é.
 */

/** Espaço normal, espaço inquebrável e espaço fino estreito. */
const ESPACOS = /[\s  ]/g;

/**
 * Ponto como separador de milhares, reconhecido só quando separa grupos de
 * exatamente três dígitos e há uma vírgula decimal a seguir. Sem esta
 * exigência, um `7.2,5` mal escrito seria reinterpretado como milhares.
 */
const MILHARES_COM_DECIMAL = /^\d{1,3}(\.\d{3})+,/;

/**
 * Converte texto escrito à portuguesa num número com ponto decimal.
 *
 * Devolve sempre uma string, para servir tanto um campo a ser escrito (onde
 * `7,` e `7.` são estados intermédios válidos) como um valor colado.
 *
 * @param {unknown} texto
 * @returns {string}
 */
export function normalizarNumeroPt(texto) {
    if (texto === null || texto === undefined) {
        return '';
    }

    let s = String(texto).trim();

    if (s === '') {
        return '';
    }

    // O sinal sai da frente e volta no fim: se ficasse, o filtro de caracteres
    // apagava-o e o valor mudava de sentido.
    const negativo = s.startsWith('-');
    if (negativo || s.startsWith('+')) {
        s = s.slice(1);
    }

    s = s.replace(ESPACOS, '');

    if (s.includes(',')) {
        if (MILHARES_COM_DECIMAL.test(s)) {
            s = s.replace(/\./g, '');
        }
        s = s.replace(/,/g, '.');
    }

    // Só dígitos e pontos. Uma letra cair é visível — o caracter simplesmente
    // não aparece no campo.
    s = s.replace(/[^0-9.]/g, '');

    // Mais de um ponto é ambíguo. Trunca no segundo em vez de juntar.
    const partes = s.split('.');
    if (partes.length > 2) {
        s = partes[0] + '.' + partes[1];
    }

    if (s === '' || s === '.') {
        return negativo && s === '.' ? '-.' : s;
    }

    return (negativo ? '-' : '') + s;
}
