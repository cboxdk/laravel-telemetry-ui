// Number/time formatting, matching src/Support/Format.php so values read the
// same whether the server or the client formatted them.

export function count(n: number | null | undefined): string {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    const abs = Math.abs(n);
    if (abs >= 1e9) return trim(n / 1e9) + 'B';
    if (abs >= 1e6) return trim(n / 1e6) + 'M';
    if (abs >= 1e3) return trim(n / 1e3) + 'k';
    if (Number.isInteger(n)) return String(n);
    if (abs > 0 && abs < 1) return String(Math.round(n * 100) / 100 || '<0.01');
    return trim(n);
}

export function ms(v: number | null | undefined): string {
    if (v === null || v === undefined || Number.isNaN(v)) return '—';
    if (v >= 60_000) return trim(v / 60_000) + 'm';
    if (v >= 1000) return trim(v / 1000) + 's';
    if (v >= 100) return Math.round(v) + 'ms';
    if (v >= 1) return trim(v) + 'ms';
    if (v > 0) return Math.round(v * 1000) + 'µs';
    return '0ms';
}

export function percent(ratio: number | null | undefined): string {
    if (ratio === null || ratio === undefined || Number.isNaN(ratio)) return '—';
    const p = ratio * 100;
    if (p === 0) return '0%';
    if (p < 0.1) return '<0.1%';
    return (p >= 10 ? Math.round(p) : Math.round(p * 10) / 10) + '%';
}

export function bytes(b: number): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (Math.abs(b) >= 1024 && i < units.length - 1) {
        b /= 1024;
        i++;
    }
    return trim(b) + ' ' + units[i];
}

function trim(n: number): string {
    return (Math.round(n * 10) / 10).toString();
}

export function clock(msEpoch: number, withSeconds = true): string {
    const d = new Date(msEpoch);
    const pad = (x: number) => String(x).padStart(2, '0');
    return `${pad(d.getHours())}:${pad(d.getMinutes())}${withSeconds ? ':' + pad(d.getSeconds()) : ''}`;
}

export function dateTime(msEpoch: number): string {
    const d = new Date(msEpoch);
    const pad = (x: number) => String(x).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)} ${clock(msEpoch)}`;
}

export function ago(msEpoch: number, now = Date.now()): string {
    const s = Math.max(0, Math.round((now - msEpoch) / 1000));
    if (s < 60) return `${s}s ago`;
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    return `${Math.floor(s / 86400)}d ago`;
}

export function statusTone(status: string | null | undefined): 'ok' | 'warn' | 'danger' | 'dim' {
    const n = Number(status);
    if (!status || Number.isNaN(n)) return 'dim';
    if (n >= 500) return 'danger';
    if (n >= 400) return 'warn';
    return 'ok';
}

export function shortId(id: string, n = 8): string {
    return id.length > n ? id.slice(0, n) : id;
}
