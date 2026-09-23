// localStorage for per-viewer conveniences only (rail pinned, theme). Every
// access is guarded: private windows and blocked storage must not break the app.
export function load<T>(key: string, fallback: T): T {
    try {
        const raw = localStorage.getItem(`tui:${key}`);
        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
}

export function save(key: string, value: unknown): void {
    try {
        localStorage.setItem(`tui:${key}`, JSON.stringify(value));
    } catch {
        /* storage unavailable */
    }
}
