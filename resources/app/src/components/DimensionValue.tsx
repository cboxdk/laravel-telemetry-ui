import { useQuery } from '@tanstack/react-query';
import { createContext, useContext, useMemo, type ReactNode } from 'react';
import type { Bootstrap, DimensionDef, Link as LinkData, Signal } from '../api/types';
import { loadLabel } from '../lib/dimensionLabels';
import { useNavigation } from '../lib/navigation';
import { useGo } from '../lib/links';
import { formatFilter, list, parseSearch, scopeOf, withFilter, type Op } from '../lib/search';
import { Icon } from './Icon';
import { Popover } from './Popover';

export const BootContext = createContext<Bootstrap | null>(null);

export function useBoot(): Bootstrap {
    const boot = useContext(BootContext);
    if (!boot) throw new Error('Bootstrap not loaded');
    return boot;
}

export function useDimension(key: string): DimensionDef | undefined {
    const boot = useContext(BootContext);
    return useMemo(() => boot?.dimensions.find((d) => d.key === key), [boot, key]);
}

/**
 * The host's display name for a dimension value ("Acme ApS" for customer
 * 8655), when the dimension has a resolver. Batched and cached; null while
 * loading, for unknown ids, and for dimensions without a resolver.
 */
export function useValueLabel(key: string, value: string): string | null {
    const dim = useDimension(key);
    const enabled = Boolean(dim?.resolvable) && value !== '' && value !== '(none)';
    const { data } = useQuery({
        queryKey: ['dim-label', key, value],
        queryFn: () => loadLabel(key, value),
        enabled,
        staleTime: 5 * 60_000,
        gcTime: 30 * 60_000,
        retry: false,
    });
    return enabled ? data ?? null : null;
}

/** A value as its name plus the raw id, or just the value. */
export function ValueText({ dimKey, value }: { dimKey: string; value: string }) {
    const name = useValueLabel(dimKey, value);
    return name ? <><span className="t-dimval-name">{name}</span><span className="t-dimval-id mono">{value}</span></> : <>{value}</>;
}

/**
 * Drill-down on a value: filter to it, exclude it, group by its key, open its
 * entity page, or follow the host's link out. When the current page is an
 * Explore surface the filter lands on it; elsewhere it opens Explore.
 */
export function useDrill() {
    const navigation = useNavigation();

    return useMemo(() => {
        const search = parseSearch(navigation.searchStr);
        const onExplore = navigation.pathname.startsWith('/explore/');
        const signal: Signal = onExplore ? (navigation.pathname.split('/')[2] as Signal) : 'requests';

        const apply = (key: string, value: string, op: Op) => {
            const base = onExplore ? list(search, 'where') : [];
            const where = withFilter(base, { key, op, value });
            navigation.go({ pathname: `/explore/${signal}`, search: onExplore ? { ...search, where } : { ...scopeOf(search), where } });
        };

        return {
            filter: (key: string, value: string) => apply(key, value, '='),
            exclude: (key: string, value: string) => apply(key, value, '!='),
            groupBy: (key: string) => {
                navigation.go({ pathname: `/explore/${signal}`, search: onExplore ? { ...search, groupBy: key } : { ...scopeOf(search), groupBy: key } });
            },
            open: (entity: string, value: string) => {
                navigation.go({ pathname: `/entity/${encodeURIComponent(entity)}`, search: { ...scopeOf(search), value } });
            },
        };
    }, [navigation]);
}

/**
 * A dimension value you can act on. Declared (custom) dimensions render as
 * violet chips; built-ins stay quiet until hovered.
 */
export function DimensionValue({ dimKey, value, children, chip, label, linkOut, link, onParam }: {
    dimKey: string;
    value: string;
    children?: ReactNode;
    chip?: boolean;
    label?: boolean;
    linkOut?: string | null;
    /** The cell's own drill-down, offered first (e.g. "filter this panel"). */
    link?: LinkData;
    onParam?: (params: Record<string, string>) => void;
}) {
    const go = useGo();
    const dim = useDimension(dimKey);
    const drill = useDrill();
    const custom = dim ? !dim.builtin : false;
    const entity = dim?.entity;
    const canOpen = Boolean(dim && (custom || dim.entity !== dim.key));
    const name = useValueLabel(dimKey, value);

    return (
        <Popover
            align="left"
            trigger={(toggle, open) => (
                <button
                    type="button"
                    className={`t-dimval ${chip ? 't-minchip' : ''} ${custom ? 'is-custom' : ''} ${open ? 'is-open' : ''}`}
                    onClick={(e) => { e.stopPropagation(); toggle(); }}
                    title={`${dim?.label ?? dimKey} = ${value}${name ? ` (${name})` : ''}`}
                >
                    {label && <span className="k">{dim?.label ?? dimKey}</span>}
                    {name
                        ? <span className="v"><span className="t-dimval-name">{name}</span><span className="t-dimval-id mono">{value}</span></span>
                        : children ?? <span className="v">{value}</span>}
                </button>
            )}
        >
            {(close) => (
                <div className="t-menu" onClick={(e) => e.stopPropagation()}>
                    <div className="t-menu-head">
                        <span className="t-eyebrow">{dim?.label ?? dimKey}</span>
                        {name && <strong className="t-menu-name">{name}</strong>}
                        <code>{value}</code>
                    </div>
                    {link && link.to === 'param' && onParam && (
                        <button type="button" onClick={() => { close(); onParam(link.params); }}><Icon name="filter" size={13} />Filter this panel</button>
                    )}
                    {link && link.to !== 'param' && (
                        <button type="button" onClick={() => { close(); go(link); }}><Icon name="chevronRight" size={13} />Open</button>
                    )}
                    <button type="button" onClick={() => { close(); drill.filter(dimKey, value); }}><Icon name="filter" size={13} />{link?.to === "param" ? "Filter Explore to this" : "Filter to this"}</button>
                    <button type="button" onClick={() => { close(); drill.exclude(dimKey, value); }}><Icon name="x" size={13} />Exclude</button>
                    <button type="button" onClick={() => { close(); drill.groupBy(dimKey); }}><Icon name="group" size={13} />Group by {dim?.label ?? dimKey}</button>
                    {canOpen && entity && (
                        <button type="button" onClick={() => { close(); drill.open(entity, value); }}><Icon name="chevronRight" size={13} />Open {dim?.label ?? dimKey}</button>
                    )}
                    {linkOut && (
                        <a href={linkOut} target="_blank" rel="noopener noreferrer" onClick={close}><Icon name="external" size={13} />Open in app</a>
                    )}
                    <button type="button" onClick={() => { close(); void navigator.clipboard?.writeText(value); }}><Icon name="copy" size={13} />Copy value</button>
                    <div className="t-menu-foot mono">{formatFilter({ key: dimKey, op: '=', value })}</div>
                </div>
            )}
        </Popover>
    );
}
