import { useEffect } from 'react';
import { boot } from '../boot';

/** `Requests · Telemetry` — tabs and history entries say where they point. */
export function useTitle(...parts: (string | null | undefined)[]): void {
    const title = [...parts.filter((p): p is string => Boolean(p)), boot().brand.name].join(' · ');
    useEffect(() => {
        document.title = title;
    }, [title]);
}
