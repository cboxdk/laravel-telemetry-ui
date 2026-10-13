import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CopyMarkdownButton } from './CopyMarkdownButton';

const snapshot = { markdown: '# Trace: GET /orders — ok, 120 ms\n', bytes: 34, truncated: false, omitted: [] };

function json(body: unknown, status = 200): Promise<Response> {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

/** A clipboard that remembers what was written, through either API. */
function stubClipboard(withWrite: boolean) {
    const written: string[] = [];
    const clipboard = {
        writeText: vi.fn(async (text: string) => { written.push(text); }),
        ...(withWrite ? {
            write: vi.fn(async (items: { types: string[]; getType: (t: string) => Promise<Blob> }[]) => {
                for (const item of items) written.push(await (await item.getType('text/plain')).text());
            }),
        } : {}),
    };
    Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true });
    return { clipboard, written };
}

/** The pending-promise ClipboardItem Safari and Chromium take. */
class ClipboardItemStub {
    readonly types: string[];
    constructor(private readonly items: Record<string, Promise<Blob>>) { this.types = Object.keys(items); }
    getType(type: string): Promise<Blob> { return this.items[type]!; }
}

beforeEach(() => vi.useRealTimers());
afterEach(() => {
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'clipboard');
});

describe('Copy as Markdown', () => {
    it('fetches the snapshot only when clicked, copies it and says so', async () => {
        const fetch = vi.fn((_input: string) => json(snapshot));
        vi.stubGlobal('fetch', fetch);
        vi.stubGlobal('ClipboardItem', ClipboardItemStub);
        const { written } = stubClipboard(true);

        render(<CopyMarkdownButton path="traces/abc/markdown" params={{ at: 1735689600000 }} />);

        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: /Copy as Markdown/ }));

        expect(await screen.findByText('Copied')).toBeInTheDocument();
        expect(written).toEqual([snapshot.markdown]);
        expect(String(fetch.mock.calls[0]![0])).toBe('/api/v2/traces/abc/markdown?at=1735689600000');
    });

    it('falls back to writeText where the browser has no ClipboardItem', async () => {
        vi.stubGlobal('fetch', vi.fn(() => json(snapshot)));
        const { clipboard, written } = stubClipboard(false);

        render(<CopyMarkdownButton path="traces/abc/markdown" />);
        fireEvent.click(screen.getByRole('button'));

        expect(await screen.findByText('Copied')).toBeInTheDocument();
        expect(clipboard.writeText).toHaveBeenCalledOnce();
        expect(written).toEqual([snapshot.markdown]);
    });

    it('says when the snapshot was cut to fit', async () => {
        vi.stubGlobal('fetch', vi.fn(() => json({ ...snapshot, bytes: 12_200, truncated: true, omitted: ['Query groups'] })));
        stubClipboard(false);

        render(<CopyMarkdownButton path="traces/abc/markdown" />);
        fireEvent.click(screen.getByRole('button'));

        expect(await screen.findByText('Copied, cut to 12 KB')).toBeInTheDocument();
    });

    it('says why when the snapshot could not be built, and copies nothing', async () => {
        vi.stubGlobal('fetch', vi.fn(() => json({ error: { type: 'not_found', message: 'Trace abc was not found.' } }, 404)));
        vi.stubGlobal('ClipboardItem', ClipboardItemStub);
        const { written } = stubClipboard(true);

        render(<CopyMarkdownButton path="traces/abc/markdown" />);
        fireEvent.click(screen.getByRole('button'));

        expect(await screen.findByText('Could not build it: Trace abc was not found.')).toBeInTheDocument();
        expect(written).toEqual([]);
    });

    it('says so when the browser refuses the clipboard', async () => {
        vi.stubGlobal('fetch', vi.fn(() => json(snapshot)));
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn(async () => { throw new DOMException('denied', 'NotAllowedError'); }) }, configurable: true });

        render(<CopyMarkdownButton path="traces/abc/markdown" />);
        fireEvent.click(screen.getByRole('button'));

        expect(await screen.findByText('Copy failed: the browser blocked the clipboard')).toBeInTheDocument();
    });

    it('goes back to its label after confirming', async () => {
        vi.stubGlobal('fetch', vi.fn(() => json(snapshot)));
        stubClipboard(false);

        render(<CopyMarkdownButton path="traces/abc/markdown" />);
        fireEvent.click(screen.getByRole('button'));
        expect(await screen.findByText('Copied')).toBeInTheDocument();

        await waitFor(() => expect(screen.getByText('Copy as Markdown')).toBeInTheDocument(), { timeout: 3000 });
    });
});
