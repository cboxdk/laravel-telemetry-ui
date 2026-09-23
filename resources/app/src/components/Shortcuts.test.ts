import { describe, expect, it } from 'vitest';
import { periodSeconds, stepWindow } from './Shortcuts';

const NOW = 1_000_000;

describe('time stepping', () => {
    it('reads period lengths', () => {
        expect([periodSeconds('15m'), periodSeconds('2h'), periodSeconds('7d'), periodSeconds('nope')]).toEqual([900, 7200, 604800, null]);
    });

    it('steps back one window from now, keeping the length', () => {
        expect(stepWindow({ period: '1h' }, -1, NOW)).toEqual({ from: String(NOW - 7200), to: String(NOW - 3600) });
    });

    it('steps an explicit window and never past now', () => {
        expect(stepWindow({ from: String(NOW - 600), to: String(NOW - 300) }, -1, NOW)).toEqual({ from: String(NOW - 900), to: String(NOW - 600) });
        expect(stepWindow({ from: String(NOW - 600), to: String(NOW - 300) }, 1, NOW)).toEqual({ from: String(NOW - 300), to: String(NOW) });
    });

    it('does nothing without a known window', () => {
        expect(stepWindow({ period: 'all' }, 1, NOW)).toBeNull();
    });
});
