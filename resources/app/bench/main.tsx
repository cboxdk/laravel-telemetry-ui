// The waterfall benchmark page: the 5,000-span trace from test/spans.ts,
// rendered by the real component in a 900px scroll box. `run.mjs` drives it.
import '../src/styles/tokens.css';
import '../src/styles/fonts.css';
import '../src/styles/app.css';
import '../src/styles/shell.css';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { flushSync } from 'react-dom';
import { createRoot } from 'react-dom/client';
import type { Bootstrap } from '../src/api/types';
import { BootContext } from '../src/components/DimensionValue';
import { Waterfall } from '../src/components/drawer/Waterfall';
import { MemoryNavigation } from '../src/lib/navigation';
import { bigTrace, traceOf } from '../src/test/spans';

const boot = { dimensions: [], entities: [], traces: { groupThreshold: 3 } } as unknown as Bootstrap;
const data = traceOf(bigTrace(), 2000);
const scroller = document.getElementById('scroller')!;

/** Two frames later: whatever the last change was has been painted. */
const painted = () => new Promise<number>((r) => requestAnimationFrame(() => requestAnimationFrame(() => r(performance.now()))));

async function mount(): Promise<{ ms: number; rows: number }> {
    const el = document.getElementById('app')!;
    const t0 = performance.now();
    flushSync(() => createRoot(el).render(
        <QueryClientProvider client={new QueryClient()}>
            <BootContext.Provider value={boot}>
                <MemoryNavigation>
                    <Waterfall data={data} />
                </MemoryNavigation>
            </BootContext.Provider>
        </QueryClientProvider>,
    ));
    return { ms: (await painted()) - t0, rows: el.querySelectorAll('.t-wf-row').length };
}

/** Scroll top to bottom in 120 steps, one per frame; the frame intervals say whether it kept up. */
async function scroll(): Promise<{ p50: number; p95: number; max: number }> {
    const deltas: number[] = [];
    scroller.scrollTop = 0;
    let last = await painted();
    const height = scroller.scrollHeight - scroller.clientHeight;
    for (let i = 1; i <= 120; i++) {
        scroller.scrollTop = (height * i) / 120;
        const now = await new Promise<number>((r) => requestAnimationFrame(() => r(performance.now())));
        deltas.push(now - last);
        last = now;
    }
    deltas.sort((a, b) => a - b);
    return { p50: deltas[59]!, p95: deltas[113]!, max: deltas[119]! };
}

async function click(selector: string, text: string): Promise<number> {
    const el = [...document.querySelectorAll<HTMLElement>(selector)].find((e) => e.textContent?.includes(text));
    if (!el) throw new Error(`no ${selector} with "${text}"`);
    const t0 = performance.now();
    el.click();
    return (await painted()) - t0;
}

(window as unknown as { mountBench: typeof mount }).mountBench = mount;

(window as unknown as { runBench: (layout: string) => Promise<unknown> }).runBench = async (layout: string) => {
    localStorage.setItem('tui:waterfall.layout', JSON.stringify(layout));
    const m = await mount();
    const result: Record<string, unknown> = { layout, mountMs: m.ms, rowsInDom: m.rows, scroll: await scroll() };

    if (layout === 'condensed') {
        scroller.scrollTop = 0;
        await painted();
        result.expandMs = await click('.t-wf-group', '×1500');
        result.stepMs = await click('button[aria-label="Next occurrence"]', '');
        result.showAllMs = await click('.t-wf-pager .t-linkbtn', 'Show all');
        result.rowsAfterShowAll = document.querySelectorAll('.t-wf-row').length;
        result.scrollShowAll = await scroll();
        result.toFullMs = await click('.t-seg button', 'Full');
    }

    return result;
};
