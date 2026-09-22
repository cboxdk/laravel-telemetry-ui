// Facts the server embeds in the SPA shell (SpaController): where we are
// mounted, where the API and hashed chunks live, and the CSRF token.
export interface Boot {
    base: string;
    api: string;
    assets: string;
    csrf: string;
    brand: { name: string; logo: string | null; accent: string | null };
}

const fallback: Boot = {
    base: '',
    api: '/api/v2',
    assets: '/build',
    csrf: '',
    brand: { name: 'Telemetry', logo: null, accent: null },
};

let cached: Boot | null = null;

export function boot(): Boot {
    if (cached) return cached;

    const el = typeof document !== 'undefined' ? document.getElementById('telemetry-ui-boot') : null;

    try {
        cached = el?.textContent ? { ...fallback, ...(JSON.parse(el.textContent) as Partial<Boot>) } : fallback;
    } catch {
        cached = fallback;
    }

    return cached;
}

/** Test hook. */
export function setBoot(value: Partial<Boot>): void {
    cached = { ...fallback, ...value };
}
