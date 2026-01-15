const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs/promises');
const path = require('path');

const BASE_URL = 'http://127.0.0.1:8890';
const PRODUCT_ID = 13;
const PRODUCT_NAME = 'Produto QA';
const ADMIN_EMAIL = 'ml@mmlins.combr';
const ADMIN_PASSWORD = 'mkcd61la';
const OUTPUT_DIR = path.join(process.cwd(), 'qa_artifacts', 'responsive');
const SNAPSHOT_DIR = path.join(OUTPUT_DIR, new Date().toISOString().replace(/[:.]/g, '-'));

const viewports = [
  { label: '360x640-portrait', width: 360, height: 640, orientation: 'portrait' },
  { label: '360x640-landscape', width: 640, height: 360, orientation: 'landscape' },
  { label: '375x667-portrait', width: 375, height: 667, orientation: 'portrait' },
  { label: '375x667-landscape', width: 667, height: 375, orientation: 'landscape' },
  { label: '390x844-portrait', width: 390, height: 844, orientation: 'portrait' },
  { label: '390x844-landscape', width: 844, height: 390, orientation: 'landscape' },
  { label: '768x1024-portrait', width: 768, height: 1024, orientation: 'portrait' },
  { label: '768x1024-landscape', width: 1024, height: 768, orientation: 'landscape' }
];

const pages = [
  { name: 'home', url: '/?route=home' },
  { name: 'product', url: '/?route=product&id=13' },
  { name: 'cart', url: '/?route=cart', requiresCart: true },
  { name: 'checkout', url: '/?route=checkout', requiresCart: true },
  { name: 'account', url: '/?route=account' },
  { name: 'admin-login', url: '/admin.php' },
  { name: 'admin-dashboard', url: '/dashboard.php', requiresAdmin: true }
];

async function waitForServer(retries = 30) {
  for (let i = 0; i < retries; i += 1) {
    try {
      const res = await fetch(`${BASE_URL}/?route=home`, { method: 'HEAD' });
      if (res.ok) {
        return true;
      }
    } catch (err) {
      // ignore until timeout
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error('PHP server not responding on time');
}

async function createCartState(browser) {
  const statePath = path.join(OUTPUT_DIR, 'cart-state.json');
  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/?route=home`, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => typeof window.addToCart === 'function');
  await page.evaluate(({ id, name }) => {
    return window.addToCart(id, name, 1);
  }, { id: PRODUCT_ID, name: PRODUCT_NAME });
  await page.waitForTimeout(1500);
  await context.storageState({ path: statePath });
  await context.close();
  return statePath;
}

async function createAdminState(browser) {
  const statePath = path.join(OUTPUT_DIR, 'admin-state.json');
  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/admin.php`, { waitUntil: 'networkidle' });
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard.php');
  await context.storageState({ path: statePath });
  await context.close();
  return statePath;
}

async function capture() {
  await fs.mkdir(SNAPSHOT_DIR, { recursive: true });

  const server = spawn('php', ['-S', '127.0.0.1:8890', '-t', '.'], {
    cwd: process.cwd(),
    stdio: 'inherit'
  });

  const stopServer = () => {
    if (!server.killed) {
      server.kill();
    }
  };

  process.on('exit', stopServer);
  process.on('SIGINT', () => {
    stopServer();
    process.exit(1);
  });

  try {
    await waitForServer();
    const browser = await chromium.launch();

    const cartState = await createCartState(browser);
    const adminState = await createAdminState(browser);

    const diagnostics = [];

    for (const pageDef of pages) {
      for (const viewport of viewports) {
        const context = await browser.newContext({
          viewport: { width: viewport.width, height: viewport.height },
          storageState: pageDef.requiresCart ? cartState : pageDef.requiresAdmin ? adminState : undefined
        });
        const page = await context.newPage();
        const target = `${BASE_URL}${pageDef.url}`;
        await page.goto(target, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);

        const overflow = await page.evaluate(() => {
          const doc = document.documentElement;
          const body = document.body;
          const docOverflow = doc.scrollWidth - doc.clientWidth;
          const bodyOverflow = body.scrollWidth - body.clientWidth;
          return {
            docScrollWidth: doc.scrollWidth,
            docClientWidth: doc.clientWidth,
            bodyScrollWidth: body.scrollWidth,
            bodyClientWidth: body.clientWidth,
            docOverflow,
            bodyOverflow,
            hasHorizontalScroll: docOverflow > 1 || bodyOverflow > 1
          };
        });

        const fileName = `${pageDef.name}-${viewport.label}.png`;
        await page.screenshot({ path: path.join(SNAPSHOT_DIR, fileName), fullPage: true });
        diagnostics.push({
          page: pageDef.name,
          url: target,
          viewport: viewport.label,
          orientation: viewport.orientation,
          overflow
        });
        await context.close();
      }
    }

    await browser.close();
    await fs.writeFile(path.join(SNAPSHOT_DIR, 'diagnostics.json'), JSON.stringify(diagnostics, null, 2));
  } finally {
    stopServer();
  }
}

capture().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
