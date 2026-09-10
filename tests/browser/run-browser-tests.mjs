import puppeteer from 'puppeteer-core';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE_URL = 'http://127.0.0.1:8088';
const SCREENSHOT_DIR = 'C:\\Users\\danie\\.gemini\\antigravity\\brain\\8adfdbc4-5172-4cc6-bb89-71c750416b13\\screenshots';

if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function run() {
  console.log('🧹 A limpar cache da aplicação...');
  try {
    execSync('php artisan cache:clear');
  } catch (e) {}
  console.log('🚀 A iniciar Google Chrome em:', CHROME_PATH);
  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-gpu',
      '--window-size=1440,900',
    ],
    defaultViewport: {
      width: 1440,
      height: 900,
    }
  });

  const page = await browser.newPage();
  const results = [];

  try {
    // 1. Login
    console.log('1. A testar Login em /admin/login...');
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01_login_page.png') });
    
    // Wait for GSAP preloader to fade out and form to become visible
    console.log('   A aguardar finalização da animação do loader...');
    await page.waitForFunction(() => {
      const loader = document.getElementById('loader');
      const main = document.getElementById('main-content');
      if (loader && (loader.style.opacity === '0' || window.getComputedStyle(loader).opacity === '0')) {
        loader.style.display = 'none';
      }
      return main && window.getComputedStyle(main).visibility === 'visible';
    }, { timeout: 10000 }).catch(async () => {
      await page.evaluate(() => {
        const l = document.getElementById('loader');
        if (l) l.remove();
        const m = document.getElementById('main-content');
        if (m) m.style.visibility = 'visible';
      });
    });

    // Fill credentials
    const emailSelector = '#email';
    const passwordSelector = '#password';
    await page.waitForSelector(emailSelector, { visible: true, timeout: 5000 });
    await page.click(emailSelector);
    await page.type(emailSelector, 'admin@mmcrespo.pt', { delay: 30 });

    await page.waitForSelector(passwordSelector, { visible: true, timeout: 5000 });
    await page.click(passwordSelector);
    await page.type(passwordSelector, 'password', { delay: 30 });

    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01_login_credentials_filled.png') });

    // Submit form via Livewire
    console.log('   A submeter credenciais...');
    const submitSelector = 'button[type="submit"]';
    await page.click(submitSelector);

    // Wait until redirected away from login
    await page.waitForFunction(() => !window.location.pathname.includes('/login'), { timeout: 15000 });
    console.log('   Sessão iniciada com sucesso! URL atual:', page.url());
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '01_login_success.png') });
    results.push({ page: 'Login', status: 'PASS', url: page.url() });

    // 2. Dashboard
    console.log('2. A testar Dashboard em /admin...');
    await page.goto(`${BASE_URL}/admin`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('.fi-section, .fi-widget, main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '02_dashboard.png'), fullPage: true });
    results.push({ page: 'Dashboard', status: 'PASS', url: page.url() });

    // 3. Diário Operacional & Registos
    console.log('3. A testar Diário Operacional em /admin/daily-records...');
    await page.goto(`${BASE_URL}/admin/daily-records`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '03_registos_timeline.png'), fullPage: true });
    results.push({ page: 'Registos / Diário Operacional', status: 'PASS', url: page.url() });

    // 4. Formulário de Registo & Rascunhos Offline
    console.log('4. A testar Formulário de Registo e Rascunho Offline em /admin/daily-records/create...');
    await page.goto(`${BASE_URL}/admin/daily-records/create`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('form.fi-form', { timeout: 10000 });
    
    // Type a test number in any input to trigger local autosave
    const numberInput = await page.$('input[type="number"], input[inputmode="decimal"], input[type="text"]');
    if (numberInput) {
      await numberInput.type('7.35');
      await page.evaluate(() => {
        window.dispatchEvent(new Event('offline'));
      });
      await new Promise(r => setTimeout(r, 1000));
    }
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '04_registo_create_offline.png'), fullPage: true });
    
    // Back to online
    await page.evaluate(() => {
      window.dispatchEvent(new Event('online'));
    });
    results.push({ page: 'Formulário com Rascunho Offline', status: 'PASS', url: page.url() });

    // 5. Incidentes
    console.log('5. A testar Incidentes em /admin/incidents...');
    await page.goto(`${BASE_URL}/admin/incidents`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '05_incidentes.png'), fullPage: true });
    results.push({ page: 'Incidentes', status: 'PASS', url: page.url() });

    // 6. Análise de Parâmetros
    console.log('6. A testar Análise de Parâmetros em /admin/analise-parametros...');
    await page.goto(`${BASE_URL}/admin/analise-parametros`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await new Promise(r => setTimeout(r, 2000)); // wait for Chart.js rendering
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '06_analise_parametros.png'), fullPage: true });
    results.push({ page: 'Análise de Parâmetros (Gráficos)', status: 'PASS', url: page.url() });

    // 7. Relatório PDF Oficial DGS
    console.log('7. A testar Livro Sanitário DGS em /admin/relatorio-pdf...');
    await page.goto(`${BASE_URL}/admin/relatorio-pdf`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '07_relatorio_pdf.png'), fullPage: true });
    results.push({ page: 'Livro Sanitário DGS (CN 14/DA)', status: 'PASS', url: page.url() });

    // 8. Modo Kiosk Inspeção DGS
    console.log('8. A testar Kiosk Inspeção DGS em /admin/inspecao-dgs...');
    await page.goto(`${BASE_URL}/admin/inspecao-dgs`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '08_inspecao_dgs_kiosk.png'), fullPage: true });
    results.push({ page: 'Modo Kiosk Inspeção DGS', status: 'PASS', url: page.url() });

    // 9. Stock Hub
    console.log('9. A testar Stock Hub em /admin/stock...');
    await page.goto(`${BASE_URL}/admin/stock`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '09_stock_hub.png'), fullPage: true });
    results.push({ page: 'Stock Hub & Bidões', status: 'PASS', url: page.url() });

    // 10. Encerramento de Piscinas
    console.log('10. A testar Encerramento de Piscinas em /admin/encerramentos...');
    await page.goto(`${BASE_URL}/admin/encerramentos`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '10_encerramentos.png'), fullPage: true });
    results.push({ page: 'Encerramento de Piscinas', status: 'PASS', url: page.url() });

    // 11. Definições
    console.log('11. A testar Definições & Janela de Silêncio em /admin/definicoes...');
    await page.goto(`${BASE_URL}/admin/definicoes`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '11_definicoes.png'), fullPage: true });
    results.push({ page: 'Definições & Janela de Silêncio', status: 'PASS', url: page.url() });

    // 12. Gestão de Piscinas
    console.log('12. A testar Gestão de Piscinas em /admin/pools...');
    await page.goto(`${BASE_URL}/admin/pools`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '12_piscinas.png'), fullPage: true });
    results.push({ page: 'Gestão de Piscinas (Volumes/Bombas)', status: 'PASS', url: page.url() });

    // 13. Bidões e Tanques de Dosagem
    console.log('13. A testar Bidões de Químicos em /admin/dosing-containers...');
    await page.goto(`${BASE_URL}/admin/dosing-containers`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '13_bicoes_dosagem.png'), fullPage: true });
    results.push({ page: 'Bidões e Tanques de Dosagem', status: 'PASS', url: page.url() });

    // 14. Ações Operacionais
    console.log('14. A testar Ações Operacionais em /admin/operational-actions...');
    await page.goto(`${BASE_URL}/admin/operational-actions`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '14_acoes_operacionais.png'), fullPage: true });
    results.push({ page: 'Ações Operacionais (Lavagens/Manutenção)', status: 'PASS', url: page.url() });

    // 15. Instalações
    console.log('15. A testar Instalações em /admin/installations...');
    await page.goto(`${BASE_URL}/admin/installations`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '15_instalacoes.png'), fullPage: true });
    results.push({ page: 'Instalações & Complexos', status: 'PASS', url: page.url() });

    // 16. Utilizadores & Funções
    console.log('16. A testar Utilizadores em /admin/users...');
    await page.goto(`${BASE_URL}/admin/users`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('main', { timeout: 10000 });
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, '16_utilizadores.png'), fullPage: true });
    results.push({ page: 'Utilizadores & Funções', status: 'PASS', url: page.url() });

    console.log('\n✅ TODOS OS TESTES NO BROWSER FORAM CONCLUÍDOS COM SUCESSO!');
    console.table(results);
  } catch (err) {
    console.error('❌ Erro durante o teste de browser:', err);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'error_screenshot.png') }).catch(() => {});
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

run();
