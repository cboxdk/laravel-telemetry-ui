import { useLayoutEffect, useState, type RefObject } from 'react';

/** The nearest ancestor that actually scrolls (the page column, the drawer…). */
export function scrollParentOf(el: HTMLElement | null): HTMLElement | null {
    let node = el?.parentElement ?? null;
    while (node) {
        const style = getComputedStyle(node);
        if (/(auto|scroll)/.test(style.overflowY)) return node;
        node = node.parentElement;
    }
    return document.scrollingElement as HTMLElement | null;
}

/**
 * One scroll per surface: long lists virtualise against the page's own scroll
 * container instead of a small box of their own. Returns that container and
 * the list's offset inside it (the virtualiser's `scrollMargin`).
 */
export function useScrollParent(ref: RefObject<HTMLElement | null>): { element: HTMLElement | null; margin: number } {
    const [state, setState] = useState<{ element: HTMLElement | null; margin: number }>({ element: null, margin: 0 });

    useLayoutEffect(() => {
        const el = ref.current;
        const parent = scrollParentOf(el);
        if (!el || !parent) return;

        const measure = () => {
            const margin = el.getBoundingClientRect().top - parent.getBoundingClientRect().top + parent.scrollTop;
            setState((s) => (s.element === parent && Math.abs(s.margin - margin) < 1 ? s : { element: parent, margin }));
        };

        measure();
        const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measure) : null;
        observer?.observe(parent);
        if (parent.firstElementChild) observer?.observe(parent.firstElementChild);
        return () => observer?.disconnect();
    }, [ref]);

    return state;
}
