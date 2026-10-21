import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../api/client';
import { setBoot } from '../boot';
import { clock, clockMs, dateTime, sameDay } from './format';
import { BROWSER, browserTimeZone, fromInputValue, setTimeZone, timeZone, toInputValue, wallClock } from './time';

afterEach(() => {
    setTimeZone(null);
    setBoot({});
    vi.unstubAllGlobals();
});

// 2026-07-01 12:00:00.250 UTC
const NOON_UTC = Date.UTC(2026, 6, 1, 12, 0, 0, 250);

describe('timezone', () => {
    it('defaults to the browser zone, so times read as local time', () => {
        expect(timeZone()).toBe(browserTimeZone());
    });

    it('takes the host default over the browser, and the viewer pick over both', () => {
        setBoot({ timezone: 'Asia/Tokyo' });
        expect(timeZone()).toBe('Asia/Tokyo');

        setTimeZone('America/New_York');
        expect(timeZone()).toBe('America/New_York');

        // "Browser" overrides a host default rather than falling back to it.
        setTimeZone(BROWSER);
        expect(timeZone()).toBe(browserTimeZone());
    });

    it('ignores a stored pick no browser knows', () => {
        setTimeZone('Mars/Olympus_Mons');
        expect(timeZone()).toBe(browserTimeZone());
    });

    it('formats clocks in the picked zone', () => {
        setTimeZone('Asia/Tokyo');
        expect(clock(NOON_UTC)).toBe('21:00:00');
        expect(clock(NOON_UTC, false)).toBe('21:00');
        expect(clockMs(NOON_UTC)).toBe('21:00:00.250');
        expect(dateTime(NOON_UTC)).toBe('01/07 21:00:00');

        setTimeZone('UTC');
        expect(clock(NOON_UTC)).toBe('12:00:00');
    });

    it('compares days in the picked zone, not the browser\'s', () => {
        const lateUtc = Date.UTC(2026, 6, 1, 23, 30);
        const earlyNextUtc = Date.UTC(2026, 6, 2, 0, 30);

        setTimeZone('UTC');
        expect(sameDay(lateUtc, earlyNextUtc)).toBe(false);
        setTimeZone('Asia/Tokyo');
        expect(sameDay(lateUtc, earlyNextUtc)).toBe(true);
    });

    it('reads a typed range in the picked zone, across daylight saving', () => {
        // Copenhagen: UTC+1 in winter, UTC+2 in summer.
        expect(fromInputValue('2026-01-15T10:00', 'Europe/Copenhagen')).toBe(Date.UTC(2026, 0, 15, 9, 0));
        expect(fromInputValue('2026-07-15T10:00', 'Europe/Copenhagen')).toBe(Date.UTC(2026, 6, 15, 8, 0));
        expect(toInputValue(Date.UTC(2026, 6, 15, 8, 0), 'Europe/Copenhagen')).toBe('2026-07-15T10:00');
        expect(fromInputValue('not a time', 'UTC')).toBeNaN();
    });

    it('never says hour 24 at midnight', () => {
        expect(wallClock(Date.UTC(2026, 6, 1, 0, 0), 'UTC').hour).toBe(0);
    });

    it('tells the API which zone to format in', async () => {
        setTimeZone('Asia/Tokyo');
        const fetch = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
        vi.stubGlobal('fetch', fetch);

        await api.get('bootstrap');

        expect((fetch.mock.calls[0]![1] as RequestInit).headers).toMatchObject({ 'X-Timezone': 'Asia/Tokyo' });
    });
});
