import { tokenColor } from '../../lib/colors';
import { clock, count } from '../../lib/format';
import { EChart } from './EChart';

/** Time × latency (or any band) heatmap. */
export function HeatmapChart({ xs, ys, cells, height = 170, unit = 'requests' }: { xs: number[]; ys: string[]; cells: [number, number, number][]; height?: number; unit?: string }) {
    const build = () => {
        const muted = tokenColor('muted-foreground');
        const max = Math.max(1, ...cells.map((c) => c[2]));
        return {
            animation: false,
            grid: { left: 8, right: 8, top: 6, bottom: 8, containLabel: true },
            tooltip: {
                backgroundColor: tokenColor('popover'),
                borderColor: tokenColor('border'),
                textStyle: { color: tokenColor('foreground'), fontSize: 12 },
                formatter: (p: { data: [number, number, number] }) => `${clock(xs[p.data[0]] ?? 0, false)} · ${ys[p.data[1]]}<br/><strong>${count(p.data[2])}</strong> ${unit}`,
            },
            xAxis: {
                type: 'category',
                data: xs.map((x) => clock(x, false)),
                axisLabel: { color: muted, fontSize: 10, hideOverlap: true },
                axisTick: { show: false },
                axisLine: { show: false },
                splitArea: { show: false },
            },
            yAxis: {
                type: 'category',
                data: ys,
                axisLabel: { color: muted, fontSize: 10, fontFamily: 'JetBrains Mono, monospace' },
                axisTick: { show: false },
                axisLine: { show: false },
            },
            visualMap: {
                show: false,
                min: 0,
                max,
                inRange: { color: [tokenColor('primary', 0.08), tokenColor('primary', 0.55), tokenColor('primary')] },
            },
            series: [{ type: 'heatmap', data: cells, itemStyle: { borderRadius: 2, borderColor: tokenColor('card'), borderWidth: 1 } }],
        };
    };

    return <EChart build={build} height={height} deps={[xs, ys, cells]} />;
}
