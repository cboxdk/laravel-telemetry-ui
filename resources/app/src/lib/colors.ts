// ECharts paints on canvas and parses colours itself, so it can't take CSS
// variables (or reliably oklch()). Resolve a token to rgb() once per theme by
// painting it on a 1×1 canvas and reading the pixel back.
const cache = new Map<string, string>();

/**
 * Where the tokens are read from. Standalone that's the document; embedded
 * it's the provider's own root, so a host's `dark` class or token overrides on
 * that root reach the charts too — not only the CSS around them.
 */
let tokenRoot: HTMLElement | null = null;

export function setTokenRoot(element: HTMLElement | null): void {
    tokenRoot = element;
    cache.clear();
}

export function tokenColor(name: string, alpha = 1): string {
    const root = tokenRoot ?? document.documentElement;
    const theme = root.closest('.dark') !== null ? 'd' : 'l';
    const key = `${theme}:${name}:${alpha}`;
    const hit = cache.get(key);
    if (hit) return hit;

    const raw = getComputedStyle(root).getPropertyValue(`--${name}`).trim() || '#888';
    let rgb = 'rgb(136,136,136)';

    try {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d');
        if (ctx) {
            ctx.fillStyle = '#888';
            ctx.fillStyle = raw;
            ctx.fillRect(0, 0, 1, 1);
            const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
            rgb = `rgba(${r},${g},${b},${((a ?? 255) / 255) * alpha})`;
        }
    } catch {
        /* jsdom / no canvas */
    }

    cache.set(key, rgb);
    return rgb;
}

export const SERIES_TOKENS = ['chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5', 'chart-6'];

export function seriesColor(i: number): string {
    return tokenColor(SERIES_TOKENS[i % SERIES_TOKENS.length]!);
}

/** Legacy hex colours some panels send (#fbbf24 …) map onto tokens. */
export function mapLegacyColor(color: string | undefined, i: number): string {
    if (!color) return seriesColor(i);
    const c = color.toLowerCase();
    if (c.startsWith('var(--')) return tokenColor(c.slice(6, -1));
    const map: Record<string, string> = {
        '#fbbf24': 'chart-3', '#f59e0b': 'chart-3', '#f87171': 'chart-4', '#ef4444': 'chart-4',
        '#34d399': 'chart-2', '#10b981': 'chart-2', '#60a5fa': 'chart-1', '#3b82f6': 'chart-1',
        '#a1a1aa': 'muted-foreground', '#c084fc': 'chart-5', '#a78bfa': 'chart-5',
    };
    return map[c] ? tokenColor(map[c]!) : color;
}
