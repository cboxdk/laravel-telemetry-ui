import { useEffect, useRef, useState } from 'react';
import { api, type Params } from '../api/client';
import type { MarkdownSnapshot } from '../api/types';
import { Icon } from './Icon';

type State = 'idle' | 'copying' | 'copied' | 'failed';

/**
 * "Copy as Markdown": fetches a snapshot the server builds (a trace, an
 * error group) and puts it on the clipboard, saying so in the button —
 * copying, copied (and whether it was cut to fit), or why it failed.
 *
 * The snapshot is fetched on click, not with the view: it costs the server a
 * diagnosis and an exception lookup nobody needs until they ask. Safari only
 * lets a click write to the clipboard if the write starts during the click,
 * so where the browser takes a pending ClipboardItem the write starts at once
 * and the text follows; otherwise the text is fetched first and written after.
 */
export function CopyMarkdownButton({ path, params, label = 'Copy as Markdown' }: { path: string; params?: Params; label?: string }) {
    const [state, setState] = useState<State>('idle');
    const [note, setNote] = useState('');
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    const settle = (next: State, message: string) => {
        setState(next);
        setNote(message);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => { setState('idle'); setNote(''); }, next === 'failed' ? 4000 : 2000);
    };

    const copy = async () => {
        if (state === 'copying') return;
        setState('copying');
        setNote('');

        const snapshot = api.get<MarkdownSnapshot>(path, params);
        // Settled here so a failed fetch is reported once, below — not also
        // as an unhandled rejection from the ClipboardItem's copy of it.
        snapshot.catch(() => undefined);

        try {
            await write(snapshot);
            const s = await snapshot;
            settle('copied', s.truncated ? `Copied, cut to ${Math.round(s.bytes / 1024)} KB` : 'Copied');
        } catch (e) {
            const fetchFailed = await snapshot.then(() => false, () => true);
            settle('failed', fetchFailed ? `Could not build it: ${e instanceof Error ? e.message : 'request failed'}` : 'Copy failed: the browser blocked the clipboard');
        }
    };

    const text = state === 'copying' ? 'Copying…' : state === 'idle' ? label : note;

    return (
        <button
            type="button"
            className={`t-btn t-btn-sm t-btn-secondary${state === 'failed' ? ' is-failed' : ''}`}
            onClick={() => { void copy(); }}
            aria-busy={state === 'copying'}
            title="A Markdown snapshot to paste into an AI agent, a pull request or a ticket"
        >
            <Icon name="copy" size={12} />
            <span role="status" aria-live="polite">{text}</span>
        </button>
    );
}

async function write(snapshot: Promise<MarkdownSnapshot>): Promise<void> {
    const clipboard = navigator.clipboard;
    if (!clipboard) throw new Error('clipboard unavailable');

    if (typeof ClipboardItem !== 'undefined' && typeof clipboard.write === 'function') {
        try {
            await clipboard.write([new ClipboardItem({ 'text/plain': snapshot.then((s) => new Blob([s.markdown], { type: 'text/plain' })) })]);
            return;
        } catch {
            // A failed fetch fails the write too: rethrow that, don't retry it.
            await snapshot;
        }
    }

    await clipboard.writeText((await snapshot).markdown);
}
