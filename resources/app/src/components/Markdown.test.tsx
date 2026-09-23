import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Markdown } from './Markdown';

describe('Markdown (issue bodies)', () => {
    it('renders headings, lists, code and inline marks', () => {
        const { container } = render(<Markdown text={'## The gap\n\nUse `recordedMetrics()` — **exact**.\n\n- one\n- two\n\n```php\n$x = 1;\n```'} />);
        expect(screen.getByRole('heading', { name: 'The gap' })).toBeInTheDocument();
        expect(screen.getByText('recordedMetrics()').tagName).toBe('CODE');
        expect(screen.getByText('exact').tagName).toBe('STRONG');
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(container.querySelector('pre')?.textContent).toBe('$x = 1;');
    });

    it('never renders raw HTML and only links http(s)', () => {
        const { container } = render(<Markdown text={'<img src=x onerror=alert(1)> [bad](javascript:alert(1)) [ok](https://cbox.dk)'} />);
        expect(container.querySelector('img')).toBeNull();
        expect(screen.queryByRole('link', { name: 'bad' })).toBeNull();
        expect(screen.getByRole('link', { name: 'ok' })).toHaveAttribute('href', 'https://cbox.dk');
    });
});
