import { useEffect, useRef, useState } from 'react';
import type { ECharts, EChartsCoreOption } from 'echarts/core';
import { useTheme } from '../../lib/theme';

type Lib = typeof import('./echarts').default;
let lib: Promise<Lib> | null = null;
const loadLib = () => (lib ??= import('./echarts').then((m) => m.default));

/**
 * Mounts an ECharts instance and keeps it in sync: rebuilds the option when
 * inputs or the theme change (colours are resolved per theme), resizes with
 * its container, disposes on unmount.
 */
export function EChart({ build, height, onReady, onOption, className, deps }: {
    build: () => EChartsCoreOption;
    height: number;
    onReady?: (chart: ECharts) => void;
    /** Runs after every setOption (e.g. to re-arm the brush cursor). */
    onOption?: (chart: ECharts) => void;
    className?: string;
    deps: unknown[];
}) {
    const el = useRef<HTMLDivElement>(null);
    const chart = useRef<ECharts | null>(null);
    const [theme] = useTheme();
    const [ready, setReady] = useState(false);

    useEffect(() => {
        let disposed = false;
        let observer: ResizeObserver | null = null;

        void loadLib().then((echarts) => {
            if (disposed || !el.current) return;
            chart.current = echarts.init(el.current, undefined, { renderer: 'canvas' });
            observer = new ResizeObserver(() => chart.current?.resize());
            observer.observe(el.current);
            setReady(true);
            if (onReady) onReady(chart.current);
        });

        return () => {
            disposed = true;
            observer?.disconnect();
            chart.current?.dispose();
            chart.current = null;
        };
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!ready || !chart.current) return;
        chart.current.setOption(build(), { notMerge: true });
        onOption?.(chart.current);
    }, [ready, theme, ...deps]); // eslint-disable-line react-hooks/exhaustive-deps

    return <div ref={el} className={`t-echart ${className ?? ''}`} style={{ height }} />;
}
