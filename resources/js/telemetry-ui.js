import * as echarts from 'echarts';

const palette = ['#34d399', '#fbbf24', '#f87171', '#60a5fa', '#c084fc', '#f472b6', '#2dd4bf', '#a3e635'];

const baseOption = (unit) => ({
    backgroundColor: 'transparent',
    animation: false,
    grid: { left: 8, right: 8, top: 12, bottom: 4, containLabel: true },
    tooltip: {
        trigger: 'axis',
        backgroundColor: '#18181b',
        borderColor: '#27272a',
        textStyle: { color: '#e4e4e7', fontSize: 12, fontFamily: 'ui-monospace, monospace' },
        valueFormatter: (value) => value == null ? '—' : `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}${unit ? ' ' + unit : ''}`,
    },
    xAxis: {
        type: 'time',
        axisLine: { lineStyle: { color: '#27272a' } },
        axisLabel: { color: '#71717a', fontSize: 10, fontFamily: 'ui-monospace, monospace' },
        splitLine: { show: false },
    },
    yAxis: {
        type: 'value',
        axisLabel: { color: '#71717a', fontSize: 10, fontFamily: 'ui-monospace, monospace' },
        splitLine: { lineStyle: { color: '#1c1c1f' } },
    },
    legend: { show: false },
});

function register() {
    window.Alpine.data('telemetryUiChart', (series, type = 'line', unit = null) => ({
        chart: null,
        resizeHandler: null,

        init() {
            this.chart = echarts.init(this.$el, null, { renderer: 'canvas' });

            this.chart.setOption({
                ...baseOption(unit),
                color: palette,
                series: series.map((entry) => ({
                    name: entry.name,
                    type,
                    data: entry.data,
                    showSymbol: false,
                    smooth: false,
                    lineStyle: { width: 1.5 },
                    barMaxWidth: 6,
                    stack: type === 'bar' ? 'total' : undefined,
                    areaStyle: type === 'area' ? { opacity: 0.15 } : undefined,
                })),
            });

            this.resizeHandler = () => this.chart?.resize();
            window.addEventListener('resize', this.resizeHandler);
        },

        destroy() {
            window.removeEventListener('resize', this.resizeHandler);
            this.chart?.dispose();
            this.chart = null;
        },
    }));
}

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
