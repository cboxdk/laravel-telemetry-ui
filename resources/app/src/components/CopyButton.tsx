import { useState } from 'react';
import { Icon } from './Icon';

export function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
    const [done, setDone] = useState(false);
    return (
        <button
            type="button"
            className="t-btn t-btn-sm t-btn-secondary"
            onClick={async () => {
                try {
                    await navigator.clipboard.writeText(text);
                    setDone(true);
                    setTimeout(() => setDone(false), 1400);
                } catch {
                    /* clipboard unavailable */
                }
            }}
        >
            <Icon name="copy" size={12} />
            {done ? 'Copied' : label}
        </button>
    );
}
