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

const isHex32 = (s) => /^[0-9a-f]{32}$/i.test(s.trim());

// The drawer's content is server-rendered, so opening it costs a Livewire
// round trip plus the backend queries behind it. Slide the shell open
// instantly with a skeleton — the morph replaces it when the data lands.
function openDrawerShell(eyebrow) {
    const root = document.querySelector('.tui-drawer-root');
    if (!root) return;

    root.classList.add('is-open');

    const eyebrowEl = root.querySelector('.tui-drawer-eyebrow');
    if (eyebrowEl) eyebrowEl.textContent = eyebrow;

    const title = root.querySelector('.tui-drawer-title h2');
    if (title) title.textContent = 'Loading\u2026';

    const body = root.querySelector('.tui-drawer-body');
    if (body) {
        body.innerHTML = '<div class="tui-skel tui-skel-title"></div>'
            + '<div class="tui-skel tui-skel-sub"></div>'
            + '<div class="tui-skel-stats" style="padding-top: 18px;">'
            + '<div class="tui-skel tui-skel-stat"></div>'.repeat(4)
            + '</div>'
            + Array.from({ length: 9 }, (_, i) =>
                `<div class="tui-skel tui-drawer-skel-row" style="width: ${88 - i * 6}%"></div>`).join('');
    }
}

// Blade-side buttons (e.g. the annotation callout) reuse the same shell-open.
window.telemetryUiOpenDrawer = openDrawerShell;

// Docked properties-pane: on wide screens the pane pushes the page content
// aside instead of covering it (body class -> CSS margin), so the page stays
// clickable and selecting another row just swaps the pane's content.
const dockQuery = window.matchMedia('(min-width: 1100px)');

function syncDock() {
    const root = document.querySelector('.tui-drawer-root');
    document.body.classList.toggle(
        'tui-drawer-docked',
        !!root && root.classList.contains('is-open') && dockQuery.matches,
    );
}

new MutationObserver(syncDock).observe(document.documentElement, {
    subtree: true, attributes: true, attributeFilter: ['class'],
});
dockQuery.addEventListener('change', syncDock);
window.addEventListener('resize', syncDock);
document.addEventListener('DOMContentLoaded', syncDock);

// Navigation delegation. Trace/issue links open the slide-in drawer; whole
// rows (data-row-trace / data-row-href) are Nightwatch-style click targets so
// you don't have to aim for the little link. cmd/ctrl/shift-click (or the
// middle button) always falls through to a new tab / the href.
document.addEventListener('click', (e) => {
    if (e.button !== 0) return;

    const plain = !(e.metaKey || e.ctrlKey || e.shiftKey);

    // A click on the PAGE swaps the pane's content (properties-panel
    // behavior); a click INSIDE the pane stacks, keeping back-navigation.
    const replace = !e.target.closest('.tui-drawer');

    // Explicit drawer links (may live inside a clickable row).
    const trace = e.target.closest('.tui-trace-link');
    if (trace && trace.dataset.traceId && plain) {
        e.preventDefault();
        openDrawerShell('Trace');
        window.Livewire?.dispatch('telemetry-ui:open-trace', { traceId: trace.dataset.traceId, replace });
        return;
    }

    const issue = e.target.closest('.tui-issue-link');
    if (issue && issue.dataset.issueId && plain) {
        e.preventDefault();
        openDrawerShell('Issue');
        window.Livewire?.dispatch('telemetry-ui:open-issue', { issueId: issue.dataset.issueId, replace });
        return;
    }

    // Whole-row affordances: skip when the click landed on a real interactive
    // child, so inner links/buttons/inputs keep their own behavior.
    if (e.target.closest('a, button, input, select, textarea, label, [wire\\:click]')) return;

    const traceRow = e.target.closest('[data-row-trace]');
    if (traceRow && traceRow.dataset.rowTrace && plain) {
        e.preventDefault();
        openDrawerShell('Trace');
        window.Livewire?.dispatch('telemetry-ui:open-trace', { traceId: traceRow.dataset.rowTrace, replace });
        return;
    }

    const exceptionRow = e.target.closest('[data-row-exception]');
    if (exceptionRow && exceptionRow.dataset.rowException && plain) {
        e.preventDefault();
        openDrawerShell('Error group');
        window.Livewire?.dispatch('telemetry-ui:open-exception', { group: exceptionRow.dataset.rowException, replace });
        return;
    }

    const hrefRow = e.target.closest('[data-row-href]');
    if (hrefRow && hrefRow.dataset.rowHref) {
        if (plain) {
            e.preventDefault();
            window.location.href = hrefRow.dataset.rowHref;
        } else {
            window.open(hrefRow.dataset.rowHref, '_blank');
        }
    }
});

function register() {
    window.Alpine.data('telemetryUiPalette', (commands, traceBase, traceSentinel) => ({
        isOpen: false,
        query: '',
        cursor: 0,

        open() {
            this.isOpen = true;
            this.query = '';
            this.cursor = 0;
            this.$nextTick(() => this.$refs.input?.focus());
        },

        close() { this.isOpen = false; },

        maybeOpenOnSlash(e) {
            // "/" opens the palette unless typing in a field.
            const t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
            e.preventDefault();
            this.open();
        },

        get results() {
            const q = this.query.trim().toLowerCase();
            const out = [];

            if (isHex32(this.query)) {
                out.push({ type: 'Trace', label: this.query.trim(), group: 'open waterfall', href: traceBase.replace(traceSentinel, this.query.trim()) });
            }

            for (const c of commands) {
                if (!q || c.label.toLowerCase().includes(q) || c.type.toLowerCase().includes(q)) {
                    out.push(c);
                }
            }
            return out.slice(0, 50);
        },

        move(d) {
            const n = this.results.length;
            if (!n) return;
            this.cursor = (this.cursor + d + n) % n;
        },

        go() {
            const item = this.results[this.cursor];
            if (item) window.location = item.href;
        },
    }));

    window.Alpine.data('telemetryUiCopyLink', () => ({
        copied: false,
        copy() {
            navigator.clipboard?.writeText(window.location.href).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 1500);
            });
        },
    }));

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

    // The ECharts instance lives in this closure, NOT on the component:
    // Alpine deep-proxies component state, and a proxied ECharts instance
    // misbehaves (resize() no-ops, coordinate lookups fail) — which left
    // charts stuck at whatever width they measured mid-morph.
    window.Alpine.data('telemetryUiChart', (series, type = 'line', unit = null, annotations = [], window_ = {}) => {
        let chart = null;
        let resizeHandler = null;
        let sizeObserver = null;
        let hideTimer = null;

        return {
        // The annotation under the pointer (or pinned by click), plus the
        // marker line's own pixel position — the callout is ANCHORED to the
        // line, so hover and click show the exact same thing in the exact
        // same place. Hover previews; click pins.
        marker: null,
        pinned: false,

        popStyle() {
            if (!this.marker) return 'display: none';
            const wrap = this.$el.getBoundingClientRect();
            const x = Math.max(8, Math.min(this.marker.px + 10, wrap.width - 256));
            return `left: ${x}px; top: 6px;`;
        },

        showMarker(a) {
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            const px = chart ? chart.convertToPixel({ xAxisIndex: 0 }, a.xAxis) : 20;
            this.marker = { ...a, px: Number.isFinite(px) ? px : 20 };
        },

        cancelHide() {
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
        },

        scheduleHide() {
            if (this.pinned) return;
            this.cancelHide();
            hideTimer = setTimeout(() => { if (!this.pinned) this.marker = null; }, 250);
        },

        closeMarker() {
            this.cancelHide();
            this.pinned = false;
            this.marker = null;
        },

        init() {
            // Mount ECharts on the sized canvas div inside the wrapper (the
            // wrapper also hosts the annotation callout). Component init()
            // fires post-layout — a child x-init would run too early and
            // ECharts would measure a 0-width container.
            const el = this.$el.querySelector('.tui-chart') || this.$el;
            chart = echarts.init(el, null, { renderer: 'canvas' });

            const base = baseOption(unit);
            // Pin the time axis to the queried window so sparse data doesn't
            // stretch the axis across unrelated days.
            if (window_ && window_.min != null) base.xAxis.min = window_.min;
            if (window_ && window_.max != null) base.xAxis.max = window_.max;

            const stacked = type === 'bar' || type === 'area';
            const mapped = series.map((entry) => ({
                name: entry.name,
                // Bars misplace on a sparse time axis; render every timeseries
                // as a (stacked) line/area which lays out correctly and reads
                // cleanly, Grafana-style.
                type: 'line',
                data: entry.data,
                color: entry.color || undefined,
                showSymbol: false,
                smooth: false,
                step: type === 'bar' ? 'end' : false,
                lineStyle: { width: 1.5 },
                stack: stacked ? 'total' : undefined,
                areaStyle: stacked ? { opacity: type === 'bar' ? 0.35 : 0.15 } : undefined,
            }));

            // Grafana-style annotations: vertical marker lines (deploys, …)
            // attached to the first series so they share the time axis. Each
            // line gets a colored dot handle; hovering OR clicking opens the
            // same line-anchored callout (markup in chart.blade.php) — hover
            // previews it, click pins it. A clustered rollout shows ×N.
            if (annotations.length && mapped.length) {
                mapped[0].markLine = {
                    silent: false,
                    symbol: ['none', 'circle'],
                    symbolSize: 9,
                    emphasis: { lineStyle: { width: 2.5, type: 'solid' } },
                    label: {
                        show: true,
                        position: 'insideEndTop',
                        color: '#a1a1aa',
                        fontSize: 9,
                        fontFamily: 'ui-monospace, monospace',
                        formatter: (p) => p.data.markerLabel || '',
                    },
                    lineStyle: { type: 'dashed', width: 1.5 },
                    data: annotations.map((a) => ({
                        xAxis: a.xAxis,
                        markerLabel: (a.label || '') + (a.count > 1 ? ` \u00d7${a.count}` : ''),
                        marker: a,
                        label: { color: a.color || '#c084fc' },
                        itemStyle: { color: a.color || '#c084fc' },
                        lineStyle: { color: a.color || '#c084fc' },
                    })),
                };
            }

            chart.setOption({
                ...base,
                color: palette,
                series: mapped,
            });

            // Livewire streams cards in, so the container can measure 0 wide
            // at the exact init moment (and a next-frame retry still can).
            // Track the element's real size instead — that also covers the
            // sidebar drawer, orientation changes and window resizes.
            sizeObserver = new ResizeObserver(() => chart?.resize());
            sizeObserver.observe(el);

            resizeHandler = () => chart?.resize();
            window.addEventListener('resize', resizeHandler);

            // One callout, two triggers: hovering a marker line previews it
            // (and it survives moving the pointer INTO the callout); clicking
            // pins it until ✕ / Escape / a click elsewhere.
            const isMarker = (params) => params.componentType === 'markLine' && params.data && params.data.marker;

            chart.on('mouseover', (params) => {
                if (!isMarker(params) || this.pinned) return;
                this.showMarker(params.data.marker);
            });

            chart.on('mouseout', (params) => {
                if (!isMarker(params)) return;
                this.scheduleHide();
            });

            chart.on('click', (params) => {
                if (!isMarker(params)) return;
                this.showMarker(params.data.marker);
                this.pinned = true;
            });

            // Grafana-style drag-to-zoom via raw zrender events instead of an
            // always-on dataZoom brush — the brush suppresses hover tooltips,
            // so we draw our own selection band and realign the whole dashboard
            // to the dragged window on release (min 5s so a click doesn't fire).
            const zr = chart.getZr();
            let dragStart = null;

            const band = new echarts.graphic.Rect({
                silent: true, invisible: true, z: 100,
                shape: { x: 0, y: 0, width: 0, height: 0 },
                style: { fill: 'rgba(96,165,250,0.14)', stroke: 'rgba(96,165,250,0.5)', lineWidth: 1 },
            });
            zr.add(band);

            const clearBand = () => {
                dragStart = null;
                band.attr({ invisible: true, shape: { x: 0, y: 0, width: 0, height: 0 } });
            };

            zr.on('mousedown', (e) => {
                if (e.which && e.which !== 1) return; // left button only
                dragStart = e.offsetX;
            });

            zr.on('mousemove', (e) => {
                if (dragStart == null) return;
                const x0 = Math.min(dragStart, e.offsetX);
                const x1 = Math.max(dragStart, e.offsetX);
                band.attr({ invisible: false, shape: { x: x0, y: 0, width: x1 - x0, height: chart.getHeight() } });
            });

            zr.on('mouseup', (e) => {
                if (dragStart == null) return;
                const x0 = Math.min(dragStart, e.offsetX);
                const x1 = Math.max(dragStart, e.offsetX);
                clearBand();

                if (x1 - x0 < 6) return; // a click, not a drag

                const t0 = chart.convertFromPixel({ xAxisIndex: 0 }, x0);
                const t1 = chart.convertFromPixel({ xAxisIndex: 0 }, x1);
                if (t0 != null && t1 != null && t1 - t0 > 5000) {
                    window.telemetryUiSetRange(t0, t1);
                }
            });

            // Dragging out of the chart cancels rather than leaving a stuck band.
            zr.on('globalout', clearBand);
        },

        destroy() {
            window.removeEventListener('resize', resizeHandler);
            sizeObserver?.disconnect();
            sizeObserver = null;
            chart?.dispose();
            chart = null;
        },
        };
    });
}

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
