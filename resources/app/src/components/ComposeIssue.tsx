import { useState } from 'react';
import { useCreateIssue } from '../api/hooks';
import type { TicketDraft } from '../api/types';
import { useGo } from '../lib/links';
import { Icon } from './Icon';

/** Compose-ticket: file a tracker issue from an error (POST /api/v2/issues). */
export function ComposeIssue({ draft, onClose }: { draft: TicketDraft; onClose: () => void }) {
    const [title, setTitle] = useState(draft.title);
    const [body, setBody] = useState(draft.body);
    const [labels, setLabels] = useState(draft.labels.join(', '));
    const create = useCreateIssue();
    const go = useGo();

    return (
        <div className="t-modal-backdrop" role="presentation" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <form
                className="t-modal"
                role="dialog"
                aria-label="Create issue"
                onSubmit={(e) => {
                    e.preventDefault();
                    create.mutate(
                        { title, body, labels: labels.split(',').map((l) => l.trim()).filter(Boolean) },
                        { onSuccess: (issue) => { onClose(); go({ to: 'issue', id: issue.id }); } },
                    );
                }}
            >
                <header className="t-modal-head">
                    <h3>Create issue</h3>
                    <button type="button" className="t-iconbtn" onClick={onClose} aria-label="Close"><Icon name="x" size={15} /></button>
                </header>
                <label className="t-field">Title<input className="t-input" value={title} onChange={(e) => setTitle(e.target.value)} required /></label>
                <label className="t-field">Description<textarea className="t-input t-textarea" rows={10} value={body} onChange={(e) => setBody(e.target.value)} /></label>
                <label className="t-field">Labels<input className="t-input" value={labels} onChange={(e) => setLabels(e.target.value)} placeholder="bug, backend" /></label>
                {create.error && <p className="t-panel-error">{create.error.message}</p>}
                <footer className="t-row-gap t-modal-foot">
                    <button type="button" className="t-btn t-btn-ghost" onClick={onClose}>Cancel</button>
                    <button type="submit" className="t-btn t-btn-primary" disabled={create.isPending}>{create.isPending ? 'Creating…' : 'Create issue'}</button>
                </footer>
            </form>
        </div>
    );
}
