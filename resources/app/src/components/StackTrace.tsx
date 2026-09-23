import { useMemo, useState } from 'react';

interface Frame {
    n: string;
    file: string;
    line: string;
    call: string;
    vendor: boolean;
    raw: string;
}

const FRAME = /^#(\d+)\s+(.+?)\((\d+)\):\s*(.*)$/;

/** PHP "#0 /path/File.php(46): Class->method()" lines → frames; others kept raw. */
export function parseFrames(text: string): Frame[] {
    const lines = text.split('\n').filter((l) => l.trim() !== '');
    const frames = lines.map((raw): Frame => {
        const m = FRAME.exec(raw.trim());
        if (!m) return { n: '', file: '', line: '', call: raw.trim(), vendor: false, raw };
        const file = m[2]!;
        return { n: m[1]!, file, line: m[3]!, call: m[4]!, vendor: file.includes('/vendor/') || file.startsWith('[internal'), raw };
    });

    // Strip the project root every path shares (…/my-app/), so "app/Http/…" reads.
    const paths = frames.map((f) => f.file).filter((f) => f.startsWith('/'));
    const root = commonRoot(paths);
    return root === '' ? frames : frames.map((f) => ({ ...f, file: f.file.startsWith(root) ? f.file.slice(root.length) : f.file }));
}

function commonRoot(paths: string[]): string {
    if (paths.length === 0) return '';
    // Composer's vendor dir sits in the project root: the most reliable anchor.
    const roots = [...new Set(paths.filter((p) => p.includes('/vendor/')).map((p) => p.slice(0, p.indexOf('/vendor/') + 1)))];
    if (roots.length === 1) return roots[0]!;
    // Otherwise the longest directory prefix every path shares.
    let prefix = paths[0]!.slice(0, paths[0]!.lastIndexOf('/') + 1);
    for (const p of paths) while (prefix !== '' && !p.startsWith(prefix)) prefix = prefix.slice(0, prefix.slice(0, -1).lastIndexOf('/') + 1);
    return prefix.length > 1 ? prefix : '';
}

type Block = { kind: 'frame'; frame: Frame } | { kind: 'vendor'; frames: Frame[]; key: string };

/**
 * A stacktrace that reads like Sentry's: app frames stand out, runs of
 * framework frames collapse to one expandable line, and paths are relative
 * to the project root.
 */
export function StackTrace({ text }: { text: string }) {
    const frames = useMemo(() => parseFrames(text), [text]);
    const [open, setOpen] = useState<Set<string>>(new Set());
    const [raw, setRaw] = useState(false);

    const blocks = useMemo(() => {
        const out: Block[] = [];
        let run: Frame[] = [];
        const flush = () => {
            if (run.length >= 3) out.push({ kind: 'vendor', frames: run, key: run[0]!.n });
            else run.forEach((frame) => out.push({ kind: 'frame', frame }));
            run = [];
        };
        for (const frame of frames) {
            if (frame.vendor) run.push(frame);
            else { flush(); out.push({ kind: 'frame', frame }); }
        }
        flush();
        return out;
    }, [frames]);

    const appFrames = frames.filter((f) => f.n !== '' && !f.vendor).length;

    if (raw) {
        return (
            <div className="t-stack-trace">
                <div className="t-stack-bar"><span className="t-dim">{frames.length} frames</span><button type="button" className="t-linkbtn" onClick={() => setRaw(false)}>Grouped view</button></div>
                <pre className="t-code">{text}</pre>
            </div>
        );
    }

    return (
        <div className="t-stack-trace">
            <div className="t-stack-bar">
                <span className="t-dim">{appFrames} app frame{appFrames === 1 ? '' : 's'} · {frames.length - appFrames} framework</span>
                <button type="button" className="t-linkbtn" onClick={() => setRaw(true)}>Raw</button>
            </div>
            <ol className="t-stack">
                {blocks.map((b) => b.kind === 'frame' ? <FrameRow key={b.frame.raw} frame={b.frame} /> : open.has(b.key)
                    ? b.frames.map((f) => <FrameRow key={f.raw} frame={f} />)
                    : (
                        <li key={b.key} className="t-stack-fold">
                            <button type="button" onClick={() => setOpen((o) => new Set(o).add(b.key))}>
                                {b.frames.length} framework frames <span className="t-dim mono">#{b.frames[0]!.n}–#{b.frames[b.frames.length - 1]!.n}</span>
                            </button>
                        </li>
                    ))}
            </ol>
        </div>
    );
}

function FrameRow({ frame }: { frame: Frame }) {
    if (frame.n === '') return <li className="t-stack-frame is-raw mono">{frame.call}</li>;
    return (
        <li className={`t-stack-frame ${frame.vendor ? 'is-vendor' : 'is-app'}`}>
            <span className="t-stack-n mono">#{frame.n}</span>
            <span className="t-stack-call mono">{frame.call}</span>
            <span className="t-stack-file mono">{frame.file}<span className="t-dim">:{frame.line}</span></span>
        </li>
    );
}
