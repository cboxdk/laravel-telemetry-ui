import { useCallback, useEffect, useState } from 'react';

export type Theme = 'light' | 'dark';

function current(): Theme {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/** Light default + .dark; the shell's pre-paint script applies it before React. */
export function useTheme(): [Theme, () => void] {
    const [theme, setTheme] = useState<Theme>(() => (typeof document === 'undefined' ? 'light' : current()));

    useEffect(() => {
        const obs = new MutationObserver(() => setTheme(current()));
        obs.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        return () => obs.disconnect();
    }, []);

    const toggle = useCallback(() => {
        const next: Theme = current() === 'dark' ? 'light' : 'dark';
        document.documentElement.classList.toggle('dark', next === 'dark');
        try {
            localStorage.setItem('tui:theme', next);
        } catch {
            /* ignore */
        }
    }, []);

    return [theme, toggle];
}
