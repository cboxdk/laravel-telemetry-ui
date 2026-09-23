import { useEffect } from 'react';
import { boot } from '../boot';
import { useOwnsDocument } from './navigation';

/**
 * `Requests · Telemetry` — tabs and history entries say where they point.
 * Embedded, the page and its title are the host's.
 */
export function useTitle(...parts: (string | null | undefined)[]): void {
    const owns = useOwnsDocument();
    const title = [...parts.filter((p): p is string => Boolean(p)), boot().brand?.name].filter(Boolean).join(' · ');
    useEffect(() => {
        if (owns) document.title = title;
    }, [title, owns]);
}
