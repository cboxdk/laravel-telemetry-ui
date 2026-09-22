import type { ECharts } from 'echarts/core';
import { useCallback } from 'react';
import type { Annotation, ChartSeries } from '../../api/types';
import { mapLegacyColor, tokenColor } from '../../lib/colors';
import { bytes, count, dateTime, ms, percent } from '../../lib/format';
import { useSetSearch } from '../../lib/state';
import { EChart } from './EChart';

export function formatUnit(v: number | null | undefined, unit?: string | null): string {
    if (v === null || v === undefined) return '—';
    switch (unit) {
        case 'ms':
        case 'milliseconds': return ms(v);
        case 'bytes': return bytes(v);
        case 'ratio':
        case 'percent': return percent(v);
        default: return count(v);
    }
}

/**
 * A time-series chart: line / area / bar / stacked, deploy annotations as
 * marker lines, and brush-to-zoom — dragging across any chart narrows the
 * GLOBAL range (from/to in the URL), so every panel follows (cross-chart
 * time brushing, which Livewire could not do without a round-trip per card).
 */
export function TimeChart({ series, type = 'line', unit, height = 200, annotations = [], min, max, brush = true }: {
    series: ChartSeries[];
    type?: string;
    unit?: string | null;
    height?: number;
    annotations?: Annotation[];
    min?: number;
    max?: number;
    brush?: boolean;
}) {
    const set = useSetSearch();

    const onReady = useCallback((chart: ECharts) => {
        if (!brush) return;
        chart.dispatchAction({ type: 'takeGlobalCursor', key: 'brush', brushOption: { brushType: 'lineX', brushMode: 'single' } });
        chart.on('brushEnd', (params: unknown) => {
            const area = (params as { areas?: { coordRange?: [number, number] }[] }).areas?.[0]?.coordRange;
            if (!area) return;
            const [a, b] = area;
            if (b - a < 30_000) return;
            set({ from: String(Math.floor(a / 1000)), to: String(Math.ceil(b / 1000)) });
            chart.dispatchAction({ type: 'brush', areas: [] });
        });
    }, [brush, set]);

    const build = () => {
        const muted = tokenColor('muted-foreground');
        const border = tokenColor('border-subtle');
        const stacked = type === 'stacked' || type === 'area-stacked';
        const isBar = type === 'bar' || type === 'stacked';

        return {
            animation: false,
            grid: { left: 8, right: 12, top: 10, bottom: series.length > 1 ? 30 : 8, containLabel: true },
            legend: series.length > 1 ? { bottom: 0, icon: 'roundRect', itemWidth: 10, itemHeight: 4, textStyle: { color: muted, fontSize: 11 } } : undefined,
            tooltip: {
                trigger: 'axis',
                backgroundColor: tokenColor('popover'),
                borderColor: tokenColor('border'),
                textStyle: { color: tokenColor('foreground'), fontSize: 12 },
                valueFormatter: (v: number) => formatUnit(v, unit),
                axisPointer: { type: 'line', lineStyle: { color: tokenColor('border-strong') } },
            },
            brush: brush ? { toolbox: [], xAxisIndex: 0, brushStyle: { color: tokenColor('primary', 0.12), borderColor: tokenColor('primary', 0.5) } } : undefined,
            toolbox: { show: false },
            xAxis: {
                type: 'time',
                min, max,
                axisLine: { lineStyle: { color: border } },
                axisTick: { show: false },
                axisLabel: { color: muted, fontSize: 10.5, hideOverlap: true },
                splitLine: { show: false },
            },
            yAxis: {
                type: 'value',
                axisLabel: { color: muted, fontSize: 10.5, formatter: (v: number) => formatUnit(v, unit) },
                splitLine: { lineStyle: { color: border } },
            },
            series: series.map((s, i) => ({
                name: s.name,
                type: isBar ? 'bar' : 'line',
                data: s.data,
                stack: stacked ? 'total' : undefined,
                // Sparse series (a p95 with few populated buckets) would draw
                // nothing as a line — mark the points instead.
                showSymbol: s.data.filter((d) => d[1] !== null).length <= Math.max(3, s.data.length * 0.15),
                symbolSize: 5,
                smooth: 0.15,
                barMaxWidth: 14,
                lineStyle: { width: 1.6 },
                itemStyle: { color: mapLegacyColor(s.color, i) },
                areaStyle: type === 'area' || stacked || series.length === 1 ? { opacity: stacked ? 0.35 : 0.08 } : undefined,
                markLine: i === 0 && annotations.length > 0 ? {
                    symbol: 'none',
                    silent: false,
                    label: { show: false },
                    lineStyle: { color: tokenColor('violet'), type: 'dashed', width: 1 },
                    tooltip: { formatter: (p: { data: { annotation: Annotation } }) => annotationTip(p.data.annotation) },
                    data: annotations.map((a) => ({ xAxis: a.xAxis, annotation: a })),
                } : undefined,
            })),
        };
    };

    return <EChart build={build} height={height} onReady={onReady} deps={[series, type, unit, annotations, min, max]} />;
}

function annotationTip(a: Annotation): string {
    const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
    return `<strong>${esc(a.label)}</strong><br/><span style="opacity:.7">${esc(a.kind)} · ${esc(a.time || dateTime(a.xAxis))}</span>${a.notes ? '<br/>' + esc(a.notes) : ''}`;
}
