/** Tiny inline trend — pure SVG, no chart library. */
export function Sparkline({ points, tone, width = 72, height = 20, bars = false }: { points: number[]; tone?: string | null; width?: number; height?: number; bars?: boolean }) {
    if (points.length === 0) return <span className="t-dim">—</span>;
    const max = Math.max(...points, 0);
    const min = Math.min(...points, 0);
    const span = max - min || 1;
    const color = `var(--${toneVar(tone)})`;

    if (bars) {
        const w = width / points.length;
        return (
            <svg className="t-spark" width={width} height={height} aria-hidden="true">
                {points.map((p, i) => {
                    const h = Math.max(p > 0 ? 1.5 : 0, ((p - min) / span) * (height - 1));
                    return <rect key={i} x={i * w + 0.5} y={height - h} width={Math.max(1, w - 1)} height={h} fill={color} rx={0.5} />;
                })}
            </svg>
        );
    }

    const step = points.length > 1 ? width / (points.length - 1) : width;
    const d = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(1)},${(height - 1 - ((p - min) / span) * (height - 2)).toFixed(1)}`).join(' ');

    return (
        <svg className="t-spark" width={width} height={height} aria-hidden="true">
            <path d={`${d} L${width},${height} L0,${height} Z`} fill={color} opacity={0.12} />
            <path d={d} fill="none" stroke={color} strokeWidth={1.4} strokeLinejoin="round" strokeLinecap="round" />
        </svg>
    );
}

export function toneVar(tone?: string | null): string {
    switch (tone) {
        case 'danger': return 'destructive';
        case 'warn': return 'warning';
        case 'ok': return 'success';
        case 'info': return 'info';
        case 'dim': return 'muted-foreground';
        default: return 'chart-1';
    }
}
