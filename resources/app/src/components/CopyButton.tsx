import { useEffect, useRef, useState } from 'react';
import { Icon } from './Icon';

/** Copies text it already holds, and says so in the button: copied, or that the browser refused. */
export function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
    const [done, setDone] = useState<'copied' | 'failed' | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    return (
        <button
            type="button"
            className="t-btn t-btn-sm t-btn-secondary"
            onClick={async () => {
                let result: 'copied' | 'failed' = 'copied';
                try {
                    await navigator.clipboard.writeText(text);
                } catch {
                    result = 'failed'; // clipboard unavailable or refused
                }
                setDone(result);
                clearTimeout(timer.current);
                timer.current = setTimeout(() => setDone(null), 1400);
            }}
        >
            <Icon name="copy" size={12} />
            <span role="status" aria-live="polite">{done === 'copied' ? 'Copied' : done === 'failed' ? 'Copy failed' : label}</span>
        </button>
    );
}
