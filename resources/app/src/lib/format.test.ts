import { describe, expect, it } from 'vitest';
import { ago, bytes, count, ms, percent, statusTone } from './format';

describe('format (mirrors Support/Format.php)', () => {
    it('formats counts', () => {
        expect(count(42)).toBe('42');
        expect(count(1234)).toBe('1.2k');
        expect(count(18_100)).toBe('18.1k');
        expect(count(2_500_000)).toBe('2.5M');
        expect(count(0.35)).toBe('0.35');
        expect(count(null)).toBe('—');
    });

    it('formats durations', () => {
        expect(ms(0.25)).toBe('250µs');
        expect(ms(3.76)).toBe('3.8ms');
        expect(ms(112.4)).toBe('112ms');
        expect(ms(1500)).toBe('1.5s');
        expect(ms(null)).toBe('—');
    });

    it('formats ratios, bytes and statuses', () => {
        expect(percent(0.006)).toBe('0.6%');
        expect(percent(1)).toBe('100%');
        expect(percent(0.0001)).toBe('<0.1%');
        expect(bytes(2048)).toBe('2 KB');
        expect(statusTone('503')).toBe('danger');
        expect(statusTone('422')).toBe('warn');
        expect(statusTone('200')).toBe('ok');
        expect(statusTone(null)).toBe('dim');
        expect(ago(Date.now() - 90_000)).toBe('1m ago');
    });
});
