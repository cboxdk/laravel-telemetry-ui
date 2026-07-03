import * as echarts from 'echarts';

const palette = ['#34d399', '#fbbf24', '#f87171', '#60a5fa', '#c084fc', '#f472b6', '#2dd4bf', '#a3e635'];

const humanBytes = (value) => {
    const abs = Math.abs(value);
    if (abs >= 1073741824) return (value / 1073741824).toFixed(1) + ' GB';
    if (abs >= 1048576) return (value / 1048576).toFixed(1) + ' MB';
    if (abs >= 1024) return (value / 1024).toFixed(1) + ' KB';
    return value.toFixed(0) + ' B';
};

const humanMs = (value) => {
    const abs = Math.abs(value);
    if (abs >= 60000) return (value / 60000).toFixed(1) + 'min';
    if (abs >= 1000) return (value / 1000).toFixed(2) + 's';
    return value.toFixed(abs >= 10 ? 0 : 1) + 'ms';
};

const formatterFor = (unit) => {
    if (unit === 'bytes') return humanBytes;
    if (unit === 'ms') return humanMs;
    return (value) => `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}${unit ? ' ' + unit : ''}`;
};

const baseOption = (unit) => {
    const format = formatterFor(unit);

    return {
        backgroundColor: 'transparent',
        animation: false,
        grid: { left: 8, right: 8, top: 12, bottom: 4, containLabel: true },
        tooltip: {
            trigger: 'axis',
            backgroundColor: '#18181b',
            borderColor: '#27272a',
            textStyle: { color: '#e4e4e7', fontSize: 12, fontFamily: 'ui-monospace, monospace' },
            valueFormatter: (value) => value == null ? '—' : format(Number(value)),
        },
        xAxis: {
            type: 'time',
            axisLine: { lineStyle: { color: '#27272a' } },
            axisLabel: { color: '#71717a', fontSize: 10, fontFamily: 'ui-monospace, monospace' },
            splitLine: { show: false },
        },
        yAxis: {
            type: 'value',
            axisLabel: {
                color: '#71717a',
                fontSize: 10,
                fontFamily: 'ui-monospace, monospace',
                formatter: (unit === 'bytes' || unit === 'ms') ? ((value) => format(Number(value))) : undefined,
            },
            splitLine: { lineStyle: { color: '#1c1c1f' } },
        },
        legend: { show: false },
        toolbox: {
            right: 4,
            top: 0,
            itemSize: 12,
            iconStyle: { borderColor: '#71717a' },
            emphasis: { iconStyle: { borderColor: '#e4e4e7' } },
            feature: {
                dataZoom: { yAxisIndex: 'none', title: { zoom: 'Zoom to range', back: 'Reset' } },
            },
        },
    };
};

// Navigate to an absolute range (unix ms) — used by the range picker and
// chart zoom so the whole dashboard realigns, not just one chart.
window.telemetryUiSetRange = (fromMs, toMs) => {
    const url = new URL(window.location);
    url.searchParams.set('from', String(Math.floor(fromMs / 1000)));
    url.searchParams.set('to', String(Math.floor(toMs / 1000)));
    window.location = url;
};

function register() {
    window.Alpine.data('telemetryUiRefresh', () => ({
        value: sessionStorage.getItem('telemetry-ui:refresh') || '0',
        timer: null,

        init() {
            this.apply(false);
        },

        apply(persist = true) {
            if (persist) sessionStorage.setItem('telemetry-ui:refresh', this.value);
            if (this.timer) clearInterval(this.timer);
            const seconds = parseInt(this.value, 10);
            if (seconds > 0) {
                this.timer = setInterval(() => window.Livewire?.dispatch('telemetry-ui:refresh'), seconds * 1000);
            }
        },

        destroy() {
            if (this.timer) clearInterval(this.timer);
        },
    }));

    window.Alpine.data('telemetryUiRange', () => ({
        open: false,
        from: '',
        to: '',

        init() {
            const params = new URLSearchParams(window.location.search);
            const toLocal = (unix) => {
                const date = new Date(parseInt(unix, 10) * 1000);
                date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
                return date.toISOString().slice(0, 16);
            };
            if (params.get('from')) this.from = toLocal(params.get('from'));
            if (params.get('to')) this.to = toLocal(params.get('to'));
        },

        apply() {
            if (!this.from || !this.to) return;
            window.telemetryUiSetRange(new Date(this.from).getTime(), new Date(this.to).getTime());
        },
    }));

    window.Alpine.data('telemetryUiChart', (series, type = 'line', unit = null, annotations = [], window_ = {}) => ({
        chart: null,
        resizeHandler: null,

        init() {
            this.chart = echarts.init(this.$el, null, { renderer: 'canvas' });

            const base = baseOption(unit);
            // Pin the time axis to the queried window so sparse data doesn't
            // stretch the axis across unrelated days.
            if (window_ && window_.min != null) base.xAxis.min = window_.min;
            if (window_ && window_.max != null) base.xAxis.max = window_.max;

            const mapped = series.map((entry) => ({
                name: entry.name,
                type: type === 'area' ? 'line' : type,
                data: entry.data,
                color: entry.color || undefined,
                showSymbol: false,
                smooth: false,
                lineStyle: { width: 1.5 },
                barMaxWidth: 8,
                stack: type === 'bar' ? 'total' : undefined,
                areaStyle: type === 'area' ? { opacity: 0.15 } : undefined,
            }));

            // Grafana-style annotations: vertical marker lines (deploys, …)
            // attached to the first series so they share the time axis.
            if (annotations.length && mapped.length) {
                mapped[0].markLine = {
                    silent: false,
                    symbol: ['none', 'none'],
                    label: {
                        show: true,
                        position: 'insideEndTop',
                        color: '#a1a1aa',
                        fontSize: 9,
                        fontFamily: 'ui-monospace, monospace',
                        formatter: (p) => p.data.markerLabel || '',
                    },
                    lineStyle: { type: 'dashed', width: 1 },
                    data: annotations.map((a) => ({
                        xAxis: a.xAxis,
                        markerLabel: a.notes ? '⬤ ' + a.notes : '⬤',
                        lineStyle: { color: a.color || '#c084fc' },
                        tooltip: { show: true },
                    })),
                };
            }

            this.chart.setOption({
                ...base,
                color: palette,
                series: mapped,
            });

            this.resizeHandler = () => this.chart?.resize();
            window.addEventListener('resize', this.resizeHandler);

            // Toolbox zoom-select realigns the whole dashboard to the
            // selected window (min 30s so misclicks don't navigate).
            this.chart.on('datazoom', () => {
                const zoom = this.chart.getOption().dataZoom?.[0];
                if (zoom && zoom.startValue != null && zoom.endValue != null && zoom.endValue - zoom.startValue > 30000) {
                    window.telemetryUiSetRange(zoom.startValue, zoom.endValue);
                }
            });
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
