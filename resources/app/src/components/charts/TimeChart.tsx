import type { ECharts } from 'echarts/core';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { Annotation, ChartSeries } from '../../api/types';
import { mapLegacyColor, tokenColor } from '../../lib/colors';
import { bytes, count, ms, percent } from '../../lib/format';
import { useSetSearch } from '../../lib/state';
import { AnnotationMarks, groupMarks, type MarkGroup } from './AnnotationMarks';
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
    const chartRef = useRef<ECharts | null>(null);
    const annotationsRef = useRef(annotations);
    annotationsRef.current = annotations;
    const [marks, setMarks] = useState<{ groups: MarkGroup[]; width: number }>({ groups: [], width: 0 });

    // Where each annotation line sits, in pixels — recomputed after every
    // render the chart does (a resize, a new option), and only stored when
    // it moved, so the flags follow the lines without a render loop.
    const place = useCallback(() => {
        const chart = chartRef.current;
        const list = annotationsRef.current;
        if (!chart || chart.isDisposed()) return;
        const width = chart.getWidth();
        const points = list.length === 0 ? [] : list
            .map((a) => ({ a, x: Number(chart.convertToPixel({ xAxisIndex: 0 }, a.xAxis)) }))
            .filter((p) => Number.isFinite(p.x) && p.x >= 0 && p.x <= width);
        const groups = groupMarks(points);
        setMarks((prev) => (prev.width === width && prev.groups.length === groups.length && prev.groups.every((g, i) => Math.abs(g.x - groups[i]!.x) < 0.5 && g.items.length === groups[i]!.items.length)
            ? prev
            : { groups, width }));
    }, []);

    useEffect(() => { place(); }, [annotations, place]);

    const onOption = useCallback((chart: ECharts) => {
        if (brush) chart.dispatchAction({ type: 'takeGlobalCursor', key: 'brush', brushOption: { brushType: 'lineX', brushMode: 'single' } });
        place();
    }, [brush, place]);

    const onReady = useCallback((chart: ECharts) => {
        chartRef.current = chart;
        chart.on('finished', place);
        if (!brush) return;
        chart.on('brushEnd', (params: unknown) => {
            const area = (params as { areas?: { coordRange?: [number, number] }[] }).areas?.[0]?.coordRange;
            if (!area) return;
            const [a, b] = area;
            if (b - a < 30_000) return;
            set({ from: String(Math.floor(a / 1000)), to: String(Math.ceil(b / 1000)) });
            chart.dispatchAction({ type: 'brush', areas: [] });
        });
    }, [brush, set, place]);

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
                    // The flags above the chart say what a line is; the axis
                    // tooltip owns hover inside the plot.
                    silent: true,
                    label: { show: false },
                    lineStyle: { color: tokenColor('violet'), type: 'dashed', width: 1 },
                    data: annotations.map((a) => ({ xAxis: a.xAxis, lineStyle: a.color ? { color: mapLegacyColor(a.color, 0) } : undefined })),
                } : undefined,
            })),
        };
    };

    return (
        <div className="t-chart-wrap">
            <EChart build={build} height={height} onReady={onReady} onOption={onOption} deps={[series, type, unit, annotations, min, max]} />
            <AnnotationMarks groups={marks.groups} width={marks.width} />
        </div>
    );
}
