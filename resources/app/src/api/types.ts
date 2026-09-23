// The /api/v2 contract. Mirrors src/Panels/Ui.php (panel payloads) and the
// controllers under src/Http/Controllers/Api — change both sides together.

export type Tone = 'ok' | 'warn' | 'danger' | 'dim' | 'info' | null | undefined;

// ---- links: data, never URLs — the SPA owns routing ------------------------

export type Link =
    | { to: 'entity'; type: string; value: string }
    | { to: 'trace'; id: string }
    | { to: 'error'; group: string }
    | { to: 'issue'; id: string }
    | { to: 'page'; page: string; params?: Record<string, string> }
    | { to: 'explore'; signal: Signal; where?: string[]; params?: Record<string, string> }
    | { to: 'entities'; type: string }
    | { to: 'param'; params: Record<string, string> }
    | { to: 'url'; href: string };

export type LabelledLink = Link & { label?: string };

export type Signal = 'requests' | 'traces' | 'logs' | 'errors';

// ---- panel payloads ---------------------------------------------------------

export interface Stat {
    label: string;
    value: string;
    tone?: Tone | string;
    delta?: string;
    deltaTone?: string;
    points?: number[];
    sparkColor?: string;
    link?: Link;
}

export interface Control {
    param: string;
    label: string;
    type: 'select' | 'search';
    value: string;
    options?: { value: string; label: string }[];
    placeholder?: string;
}

export interface Cell {
    v: string | number | null;
    raw?: number | null;
    tone?: Tone | string;
    mono?: boolean;
    link?: Link;
    spark?: number[];
    bar?: number;
    badge?: string;
    badges?: { label: string; tone?: string }[];
    dim?: { key: string; value: string };
    sub?: string;
}

export interface Column {
    key: string;
    label: string;
    align?: 'left' | 'right' | 'center';
    width?: string;
}

export interface Base {
    kind: string;
    id?: string;
    title?: string;
    subtitle?: string | null;
    span?: number;
    error?: string | null;
    empty?: string | null;
    note?: string | null;
    drill?: LabelledLink | null;
    controls?: Control[];
    stream?: { signal: 'logs' | 'requests'; params?: Record<string, string> } | null;
    ticket?: TicketDraft | null;
    copy?: { label: string; text: string } | null;
}

export interface Annotation {
    xAxis: number;
    label: string;
    notes?: string | null;
    kind: string;
    traceId?: string | null;
    time: string;
    timeEnd?: string | null;
    count?: number;
    hosts?: string[];
    hostCount?: number;
    color?: string;
}

export interface ChartSeries {
    name: string;
    data: [number, number | null][];
    color?: string;
}

export interface ChartPayload extends Base {
    kind: 'chart';
    series: ChartSeries[];
    stats?: Stat[];
    type?: 'line' | 'bar' | 'area' | 'stacked' | string;
    unit?: string | null;
    height?: number;
    annotations?: Annotation[];
    min?: number;
    max?: number;
}

export type Row = Record<string, Cell | string | number | null | undefined | Link | TicketDraft> & { _link?: Link; _ticket?: TicketDraft };

export interface TablePayload extends Base {
    kind: 'table';
    columns: Column[];
    rows: Row[];
}

export interface BarItem {
    label: string;
    value: number;
    display?: string;
    link?: Link;
    sub?: string;
    tone?: string;
    dim?: { key: string; value: string };
}

export interface BarsPayload extends Base {
    kind: 'bars';
    items: BarItem[];
}

export interface StatsPayload extends Base {
    kind: 'stats';
    items: Stat[];
    badge?: { label: string; tone?: string; title?: string } | string;
}

export interface CompositePayload extends Base {
    kind: 'composite';
    parts: PanelPayload[];
    back?: LabelledLink;
    backLabel?: string;
    badges?: string[];
}

export interface HeaderPayload extends Base {
    kind: 'header';
    stats: Stat[];
    back?: LabelledLink;
    backLabel?: string;
    badges?: string[];
    links?: LabelledLink[];
}

export interface KvPayload extends Base {
    kind: 'kv';
    items: { label: string; value: string | number | null; mono?: boolean; link?: Link; tone?: string }[];
}

export interface CodePayload extends Base {
    kind: 'code';
    text: string;
    language?: string;
    /** 1-based line numbers to emphasise (the throw line). */
    highlight?: number[];
}

/** A prefilled issue draft for the compose form (POST /api/v2/issues). */
export interface TicketDraft {
    title: string;
    body: string;
    labels: string[];
}

export interface CalloutPayload extends Base {
    kind: 'callout';
    message: string;
    tone?: string;
}

export interface HeatmapPayload extends Base {
    kind: 'heatmap';
    xs: number[];
    ys: string[];
    cells: [number, number, number][];
    unit?: string;
    max?: number;
}

export interface GraphNode {
    id: string;
    label: string;
    kind?: string;
    color?: string;
    requests?: number;
    errors?: number;
    p95?: number;
    link?: Link;
}

export interface GraphPayload extends Base {
    kind: 'graph';
    nodes: GraphNode[];
    edges: { source: string; target: string; count: number; errors?: number; p95?: number }[];
}

export interface LogEntryRow {
    time: string;
    ms: number;
    nano?: string;
    level: string;
    tone: string;
    message: string;
    service?: string;
    labels: Record<string, string>;
    traceId?: string | null;
}

export interface LogsPayload extends Base {
    kind: 'logs';
    entries: LogEntryRow[];
}

export interface HiddenPayload extends Base {
    kind: 'hidden';
}

export type PanelPayload =
    | ChartPayload
    | TablePayload
    | BarsPayload
    | StatsPayload
    | CompositePayload
    | HeaderPayload
    | KvPayload
    | CodePayload
    | CalloutPayload
    | HeatmapPayload
    | GraphPayload
    | LogsPayload
    | HiddenPayload;

// ---- bootstrap --------------------------------------------------------------

export interface DimensionDef {
    key: string;
    label: string;
    group: string | null;
    entity: string;
    scope: 'span' | 'resource' | 'intrinsic';
    builtin: boolean;
    signals: string[];
    format: string | null;
    plural: string;
    linksOut: boolean;
    /** Has a name resolver (TelemetryUi::resolve()): ids render as names. */
    resolvable?: boolean;
}

export interface EntityDef {
    type: string;
    key: string;
    label: string;
    plural: string;
    custom: boolean;
    group: string | null;
}

export interface Bootstrap {
    app: { name: string; logo: string | null; accent: string | null; copyLink: boolean; version: string };
    nav: { group: string; pages: { slug: string; label: string }[] }[];
    pages: Record<string, { label: string; group: string | null; hidden: boolean }>;
    explore: { signal: Signal; label: string }[];
    entities: EntityDef[];
    dimensions: DimensionDef[];
    navLinks: { key: string; label: string; url: string; icon: string | null }[];
    connections: { value: string; label: string; url: string }[];
    currentConnection: string;
    scope: { services: string[]; environments: string[]; servicesLocked: boolean; environmentsLocked: boolean; error: string | null };
    state: { period: string; from: string; to: string; refresh: number; service: string; env: string };
    periods: { value: string; label: string }[];
    refreshIntervals: number[];
    abilities: { manage: boolean; createIssues: boolean };
    capabilities: { issues: boolean; exactAggregation: boolean };
    user: { name: string; email?: string | null } | null;
}

export interface PageDef {
    page: string;
    label: string;
    group: string | null;
    panels: { id: string; span: number }[];
}

// ---- explore ----------------------------------------------------------------

export interface SpanRow {
    traceId: string;
    spanId: string;
    startMs: number;
    time: string;
    durationMs: number;
    name: string;
    service: string;
    method: string | null;
    route: string | null;
    path: string | null;
    target: string | null;
    status: string | null;
    error: boolean;
    browser: boolean;
    attributes: Record<string, string>;
}

export interface ErrorRow {
    group: string;
    type: string;
    message: string;
    count: number;
    users: number;
    services: string[];
    source: 'backend' | 'frontend' | 'full-stack';
    firstMs: number;
    lastMs: number;
    traceId: string | null;
    spark: number[];
}

export interface Group {
    value: string;
    count: number;
    errors: number;
    errorRate: number;
    avg: number;
    p95: number | null;
    share: number;
}

export interface Series {
    count: [number, number][];
    errors: [number, number][];
    p95: [number, number | null][];
    bucketMs: number;
}

export interface Heatmap {
    xs: number[];
    ys: string[];
    cells: [number, number, number][];
    max: number;
    /** ms per column. */
    width?: number;
    /** [lower, upper) ms per row; upper null = open. */
    bands?: [number, number | null][];
}

export interface Sample {
    size: number;
    limit: number;
    truncated: boolean;
    exact: boolean;
    groupsExact?: boolean;
    /** A filter the backend can't evaluate (e.g. `!~` on telemetryd) was applied after sampling. */
    readSideFiltered?: boolean;
}

export interface ExploreResult<R = SpanRow | LogEntryRow | ErrorRow> {
    signal: Signal;
    rows: R[];
    stats: Record<string, number | null | Record<string, number>>;
    series: Series;
    heatmap?: Heatmap;
    groupBy: string | null;
    groups: Group[] | null;
    sample: Sample;
    range: { start: number; end: number };
    where: string[];
    /** The backend query this view compiled to (TraceQL / LogQL), if available. */
    query?: CompiledQuery | null;
}

export interface Facet {
    key: string;
    label: string;
    group: string | null;
    custom: boolean;
    values: { value: string; count: number }[];
}

export interface FacetsResult {
    signal: Signal;
    facets: Facet[];
    exact: boolean;
    sample: number;
}

// ---- entities ---------------------------------------------------------------

export interface EntityInfo {
    type: string;
    key: string;
    label: string;
    plural: string;
    group: string | null;
    custom: boolean;
    linksOut: boolean;
    value?: string;
    linkOut?: string | null;
}

export interface Red {
    count: number;
    errors: number;
    errorRate: number;
    avg: number | null;
    p50: number | null;
    p95: number | null;
    p99: number | null;
    traces: number;
    perMinute: number;
}

export interface EntityIndex {
    entity: EntityInfo;
    signal: Signal;
    unit?: 'requests' | 'spans';
    values: Group[];
    stats: Red;
    sample: Sample;
}

export interface Breakdown {
    key: string;
    label: string;
    custom: boolean;
    entity: string;
    distinct: number;
    values: { value: string; count: number; share: number; failing: number; lift: number | null }[];
    /** false for read-side keys (trace.root) that can't be filtered on. */
    drill?: boolean;
}

export interface Insight {
    tone: string;
    text: string;
    dim?: { key: string; value: string };
    link?: Link;
}

export interface CompiledQuery {
    language: string;
    text: string;
}

export interface EntityStory {
    entity: EntityInfo;
    signal: Signal;
    where: string[];
    red: Red;
    series: Series;
    heatmap: Heatmap;
    statusMix: Group[];
    insights: Insight[];
    breakdowns: Breakdown[];
    slowest: SpanRow[];
    failing: SpanRow[];
    recent: SpanRow[];
    errors: { group: string; type: string; message: string; count: number; traceId: string | null }[];
    deploys: Annotation[];
    raw: Record<string, string>;
    panels: { id: string; span: number; params: Record<string, string> }[];
    sample: Sample;
    range: { start: number; end: number };
}

// ---- traces / errors / issues ----------------------------------------------

export interface SpanData {
    spanId: string;
    parentSpanId: string | null;
    name: string;
    service: string;
    kind: string;
    startNano: string;
    startMs: number;
    durationMs: number;
    error: boolean;
    browser: boolean;
    summary: string | null;
    attributes: Record<string, string>;
    links: { traceId: string; spanId: string }[];
}

export interface ReportItem {
    name: string;
    detail: string;
    durationMs: number;
    spanId: string;
}

export interface TraceData {
    traceId: string;
    root: SpanData | null;
    durationMs: number;
    error: boolean;
    spanCount: number;
    services: Record<string, Record<string, string>>;
    waterfall: { span: SpanData; depth: number; offsetPct: number; widthPct: number; ancestors: string[]; children: number }[];
    chain: { spanId: string; service: string; name: string; durationMs: number; kind: string; color: string }[];
    identities: Record<string, { color: string; kind: string; label: string | null }>;
    context: { label: string; group: string; unit: string; current: number; avg: number; max: number; baseline: number | null; outlier: boolean; points: number[] }[];
    profile: { name: string; percent: number; count: number }[];
    report: {
        request: Record<string, string>;
        requestHeaders: Record<string, string>;
        responseHeaders: Record<string, string>;
        totals: { label: string; value: string }[];
        db: { items: ReportItem[]; duplicates: Record<string, number> };
        cache: { items: ReportItem[]; summary: Record<string, number> };
        redis: ReportItem[];
        outgoing: ReportItem[];
        queued: ReportItem[];
        views: ReportItem[];
        storage: ReportItem[];
    };
    logs: { time: string; level: string; tone: string; message: string }[];
    logsMatch?: 'trace' | 'time' | null;
    exceptions: { group: string; type: string; message: string; file: string; line: number; source: string; match: 'trace' | 'time' | 'span' }[];
    dimensionLinks: Record<string, string>;
}

export interface ErrorDetail {
    type: string;
    message: string;
    file: string;
    line: number;
    stacktrace: string;
    source: string;
    environment: string;
    release: string;
    host: string;
}

export interface ErrorGroupData {
    group: string;
    stats: { count: number; sampled: boolean; firstSeen: string; lastSeen: string; source: string; users: number } | null;
    occurrences: { nano: number; at: string; traceId: string; service: string; message: string; user: string; frontend: boolean; detail: ErrorDetail }[];
    detail: ErrorDetail | null;
    request: { traceId: string; origin: string; method: string; route: string; status: string; user: string } | null;
    suspect: { label: string; kind: string; time: string; gap: string; notes: string | null; traceId: string | null; color: string } | null;
    releases: { release: string; count: number }[];
    lookbackDays: number;
    canCreateIssue: boolean;
    tracker: string | null;
    draft: { title: string; body: string; labels: string[] } | null;
    llm: string;
}

export interface IssueData {
    id: string;
    title: string;
    state: string;
    open: boolean;
    url: string;
    body: string | null;
    labels: string[];
    author: string | null;
    assignee: string | null;
    count: number | null;
    kind: string;
    createdAt: string | null;
    updatedAt: string | null;
    traceIds: string[];
}
