import { Fragment, type ReactNode } from 'react';

/**
 * A small, safe Markdown renderer for tracker issue bodies: headings, fenced
 * code, lists, blockquotes, paragraphs, inline code / bold / italics / links.
 * It builds React elements (never raw HTML), so a hostile body can't inject
 * markup; links are limited to http(s).
 */
export function Markdown({ text }: { text: string }) {
    const lines = text.replace(/\r\n/g, '\n').split('\n');
    const blocks: ReactNode[] = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i]!;

        if (line.startsWith('```')) {
            const body: string[] = [];
            i++;
            while (i < lines.length && !lines[i]!.startsWith('```')) body.push(lines[i++]!);
            i++;
            blocks.push(<pre key={blocks.length} className="t-md-code">{body.join('\n')}</pre>);
            continue;
        }

        const heading = /^(#{1,6})\s+(.*)$/.exec(line);
        if (heading) {
            const level = Math.min(4, heading[1]!.length + 1);
            const Tag = `h${level}` as 'h2' | 'h3' | 'h4';
            blocks.push(<Tag key={blocks.length} className="t-md-h">{inline(heading[2]!)}</Tag>);
            i++;
            continue;
        }

        if (/^\s*([-*+]|\d+\.)\s+/.test(line)) {
            const ordered = /^\s*\d+\./.test(line);
            const items: string[] = [];
            while (i < lines.length && /^\s*([-*+]|\d+\.)\s+/.test(lines[i]!)) items.push(lines[i++]!.replace(/^\s*([-*+]|\d+\.)\s+/, ''));
            const List = ordered ? 'ol' : 'ul';
            blocks.push(<List key={blocks.length} className="t-md-list">{items.map((it, k) => <li key={k}>{inline(it)}</li>)}</List>);
            continue;
        }

        if (line.startsWith('>')) {
            const quote: string[] = [];
            while (i < lines.length && lines[i]!.startsWith('>')) quote.push(lines[i++]!.replace(/^>\s?/, ''));
            blocks.push(<blockquote key={blocks.length} className="t-md-quote">{inline(quote.join(' '))}</blockquote>);
            continue;
        }

        if (line.trim() === '') {
            i++;
            continue;
        }

        const para: string[] = [];
        while (i < lines.length && lines[i]!.trim() !== '' && !/^(```|#{1,6}\s|>|\s*([-*+]|\d+\.)\s)/.test(lines[i]!)) para.push(lines[i++]!);
        blocks.push(<p key={blocks.length} className="t-md-p">{inline(para.join(' '))}</p>);
    }

    return <div className="t-md">{blocks}</div>;
}

const INLINE = /(`[^`]+`|\*\*[^*]+\*\*|\*[^*\s][^*]*\*|_[^_\s][^_]*_|\[[^\]]+\]\([^)\s]+\))/g;

export function inline(text: string): ReactNode {
    const parts = text.split(INLINE);
    return parts.map((part, k) => {
        if (part.startsWith('`') && part.endsWith('`') && part.length > 1) return <code key={k}>{part.slice(1, -1)}</code>;
        if (part.startsWith('**') && part.endsWith('**')) return <strong key={k}>{part.slice(2, -2)}</strong>;
        if ((part.startsWith('*') && part.endsWith('*')) || (part.startsWith('_') && part.endsWith('_'))) return <em key={k}>{part.slice(1, -1)}</em>;
        const link = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(part);
        if (link) {
            const href = link[2]!;
            return /^https?:\/\//.test(href)
                ? <a key={k} href={href} target="_blank" rel="noopener noreferrer">{link[1]}</a>
                : <Fragment key={k}>{link[1]}</Fragment>;
        }
        return <Fragment key={k}>{part}</Fragment>;
    });
}
