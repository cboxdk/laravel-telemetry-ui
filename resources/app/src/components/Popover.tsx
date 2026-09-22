import { useEffect, useRef, useState, type ReactNode } from 'react';

export function Popover({ trigger, children, align = 'right' }: {
    trigger: (toggle: () => void, open: boolean) => ReactNode;
    children: (close: () => void) => ReactNode;
    align?: 'left' | 'right';
}) {
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="t-popover-root" ref={root}>
            {trigger(() => setOpen((o) => !o), open)}
            {open && <div className={`t-popover t-popover-${align}`}>{children(() => setOpen(false))}</div>}
        </div>
    );
}
