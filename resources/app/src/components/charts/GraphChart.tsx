import type { ECharts } from 'echarts/core';
import { useCallback } from 'react';
import type { GraphPayload, Link } from '../../api/types';
import { tokenColor } from '../../lib/colors';

// Red is reserved for failure in the graph, so healthy nodes never use it.
const CALM = ['chart-1', 'chart-2', 'chart-3', 'chart-5', 'chart-6'];
import { count, ms, percent } from '../../lib/format';
import { EChart } from './EChart';

/** Service topology — draggable force layout; clicking a node drills in. */
export function GraphChart({ data, height = 320, onNode }: { data: GraphPayload; height?: number; onNode?: (link: Link) => void }) {
    const onReady = useCallback((chart: ECharts) => {
        chart.on('click', (p: unknown) => {
            const link = (p as { data?: { link?: Link } }).data?.link;
            if (link && onNode) onNode(link);
        });
    }, [onNode]);

    const build = () => {
        const max = Math.max(1, ...data.edges.map((e) => e.count));
        return {
            animation: false,
            tooltip: {
                backgroundColor: tokenColor('popover'),
                borderColor: tokenColor('border'),
                textStyle: { color: tokenColor('foreground'), fontSize: 12 },
                formatter: (p: { dataType: string; data: Record<string, unknown> }) => {
                    const d = p.data;
                    if (p.dataType === 'edge') {
                        const errors = Number(d.errors ?? 0);
                        return `${d.source} → ${d.target}<br/>${count(Number(d.count))} calls${errors ? ` · ${percent(errors / Number(d.count))} failed` : ''}${d.p95 ? ` · p95 ${ms(Number(d.p95))}` : ''}`;
                    }
                    return `<strong>${d.name}</strong>${d.requests ? `<br/>${count(Number(d.requests))} requests` : ''}`;
                },
            },
            series: [{
                type: 'graph',
                layout: 'force',
                roam: true,
                draggable: true,
                force: { repulsion: 260, edgeLength: [90, 180], gravity: 0.08 },
                label: { show: true, position: 'bottom', color: tokenColor('foreground'), fontSize: 11, fontFamily: 'JetBrains Mono, monospace' },
                edgeSymbol: ['none', 'arrow'],
                edgeSymbolSize: 7,
                data: data.nodes.map((n, i) => ({
                    id: n.id,
                    name: n.label,
                    symbolSize: 18 + Math.min(18, Math.log10(1 + (n.requests ?? 0)) * 5),
                    itemStyle: { color: n.errors ? tokenColor('destructive') : tokenColor(CALM[i % CALM.length]!), borderColor: tokenColor('card'), borderWidth: 2 },
                    requests: n.requests,
                    link: n.link,
                })),
                links: data.edges.map((e) => ({
                    source: e.source,
                    target: e.target,
                    count: e.count,
                    errors: e.errors,
                    p95: e.p95,
                    lineStyle: { width: 1 + (e.count / max) * 4, color: e.errors ? tokenColor('destructive', 0.7) : tokenColor('border-strong'), curveness: 0.12 },
                })),
            }],
        };
    };

    return <EChart build={build} height={height} onReady={onReady} deps={[data]} />;
}
