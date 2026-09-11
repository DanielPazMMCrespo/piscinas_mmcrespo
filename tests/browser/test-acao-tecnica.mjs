import puppeteer from 'puppeteer-core';
import fs from 'fs';
import path from 'path';

const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE_URL = 'http://127.0.0.1:8088';
const SCREENSHOT_DIR = 'C:\\Users\\danie\\.gemini\\antigravity\\brain\\8adfdbc4-5172-4cc6-bb89-71c750416b13\\screenshots';

const browser = await puppeteer.launch({
  executablePath: CHROME_PATH,
  headless: true,
  args: ['--no-sandbox','--disable-setuid-sandbox','--disable-gpu','--window-size=1440,900'],
  defaultViewport: { width: 1440, height: 900 }
});

const page = await browser.newPage();
const errors = [];
const httpErrors = [];

page.on('pageerror', e => errors.push(e.message));
page.on('console', m => { if (m.type() === 'error') errors.push(m.text().substring(0,200)); });
page.on('response', res => { if (res.status() >= 500) httpErrors.push(res.status() + ' ' + res.url()); });

try {
  // LOGIN (using working method from run-browser-tests.mjs)
  await page.goto(BASE_URL + '/admin/login', { waitUntil: 'networkidle2' });
  await page.waitForFunction(() => {
    const loader = document.getElementById('loader');
    const main = document.getElementById('main-content');
    if (loader && (loader.style.opacity === '0' || window.getComputedStyle(loader).opacity === '0')) loader.style.display = 'none';
    return main && window.getComputedStyle(main).visibility === 'visible';
  }, { timeout: 10000 }).catch(async () => {
    await page.evaluate(() => {
      const l = document.getElementById('loader'); if (l) l.remove();
      const m = document.getElementById('main-content'); if (m) m.style.visibility = 'visible';
    });
  });

  await page.waitForSelector('#email', { visible: true, timeout: 5000 });
  await page.type('#email', 'admin@mmcrespo.pt', { delay: 30 });
  await page.type('#password', 'password', { delay: 30 });
  await page.click('button[type="submit"]');
  await page.waitForFunction(() => !location.pathname.includes('/login'), { timeout: 15000 });
  console.log('LOGIN OK:', page.url());

  // GO TO DAILY RECORDS
  await page.goto(BASE_URL + '/admin/daily-records', { waitUntil: 'networkidle2' });
  await page.waitForSelector('main', { timeout: 10000 });
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'acao_tecnica_01_page.png'), fullPage: false });
  console.log('PAGE LOADED OK');

  // FIND BUTTONS
  const buttons = await page.$$eval('button', bs => bs.map(b => ({
    text: b.textContent.trim().substring(0,50),
    disabled: b.disabled
  })).filter(b => b.text.length > 0));
  console.log('BUTTONS:', JSON.stringify(buttons));

  // CLICK ACAO TECNICA
  const acaoBtn = await page.evaluate(() => {
    const btns = Array.from(document.querySelectorAll('button'));
    const btn = btns.find(b => b.textContent.includes('cnica'));
    if (btn) { btn.click(); return btn.textContent.trim(); }
    return null;
  });
  console.log('CLICKED:', acaoBtn);

  if (!acaoBtn) {
    console.log('BUTTON NOT FOUND!');
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'acao_tecnica_02_no_button.png') });
  } else {
    // WAIT FOR SLIDE-OVER
    await new Promise(r => setTimeout(r, 3000));
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'acao_tecnica_02_after_click.png') });
    
    const state = await page.evaluate(() => ({
      dialogs: document.querySelectorAll('[role=dialog]').length,
      modals: document.querySelectorAll('.fi-modal-window,.fi-slide-over-panel-inner').length,
      visibleDialogs: Array.from(document.querySelectorAll('[role=dialog]')).filter(d => d.offsetParent !== null).length,
      url: location.href
    }));
    console.log('AFTER CLICK STATE:', JSON.stringify(state));
    
    if (state.dialogs > 0 || state.modals > 0) {
      console.log('SLIDE-OVER OPENED!');
      
      // Try to fill and submit form
      await new Promise(r => setTimeout(r, 1000));
      
      // Check what fields are visible
      const fields = await page.evaluate(() => {
        const inputs = Array.from(document.querySelectorAll('[role=dialog] input, [role=dialog] select'));
        return inputs.map(i => ({ name: i.name, type: i.type, value: i.value }));
      });
      console.log('FORM FIELDS:', JSON.stringify(fields));
      
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'acao_tecnica_03_slide_over.png') });
    } else {
      console.log('SLIDE-OVER DID NOT OPEN!');
    }
  }

} catch (err) {
  console.error('ERROR:', err.message);
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'acao_tecnica_error.png') }).catch(() => {});
} finally {
  console.log('JS ERRORS:', errors.length ? errors.join(' | ') : 'none');
  console.log('HTTP ERRORS:', httpErrors.length ? httpErrors.join(' | ') : 'none');
  await browser.close();
}

