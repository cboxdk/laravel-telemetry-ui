// npm run bench:waterfall [-- --throttle=4]
//
// Builds the benchmark page in production mode (React's dev build would
// measure its own warnings), serves it, and renders the 5,000-span trace in
// headless Chromium five times per layout. Prints medians. Needs a Playwright
// Chromium (`npx playwright install chromium`).
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { chromium } from 'playwright';
import { build, preview } from 'vite';

const root = dirname(fileURLToPath(import.meta.url));
const throttle = Number(process.argv.find((a) => a.startsWith('--throttle='))?.split('=')[1] ?? 1);
const outDir = mkdtempSync(join(tmpdir(), 'tui-bench-'));
const config = { configFile: false, root, base: './', plugins: [react()], logLevel: 'error', build: { outDir, emptyOutDir: true } };

await build(config);
const server = await preview({ ...config, preview: { port: 0 } });
const url = server.resolvedUrls.local[0];
const browser = await chromium.launch();
const results = {};

for (const layout of ['full', 'condensed']) {
    results[layout] = [];
    for (let i = 0; i < 5; i++) {
        const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
        if (throttle > 1) await (await page.context().newCDPSession(page)).send('Emulation.setCPUThrottlingRate', { rate: throttle });
        await page.goto(`${url}index.html`);
        await page.waitForFunction(() => typeof window.runBench === 'function');
        results[layout].push(await page.evaluate((l) => window.runBench(l), layout));
        await page.close();
    }
}

await browser.close();
server.httpServer.close();
rmSync(outDir, { recursive: true, force: true });

const median = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)].toFixed(1);
const at = (runs, path) => median(runs.map((r) => path.split('.').reduce((o, k) => o[k], r)));

console.log(`5,000 spans, CPU throttle ${throttle}×, median of 5 (ms; "→ paint" includes a two-frame floor of ~33 ms)`);
for (const [layout, runs] of Object.entries(results)) {
    console.log(`${layout.padEnd(10)} mount → paint ${at(runs, 'mountMs')}, rows in DOM ${runs[0].rowsInDom}, scroll frame p50/p95/max ${at(runs, 'scroll.p50')}/${at(runs, 'scroll.p95')}/${at(runs, 'scroll.max')}`);
    if (layout === 'condensed') {
        console.log(`           expand 1,500 → paint ${at(runs, 'expandMs')}, step ${at(runs, 'stepMs')}, show all ${at(runs, 'showAllMs')} (${runs[0].rowsAfterShowAll} rows in DOM), scroll p95/max ${at(runs, 'scrollShowAll.p95')}/${at(runs, 'scrollShowAll.max')}, switch to full ${at(runs, 'toFullMs')}`);
    }
}
