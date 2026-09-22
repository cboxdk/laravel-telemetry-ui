import type { Bootstrap } from '../../api/types';
import type { IconName } from '../Icon';
import { groupIcon } from '../Icon';

export interface NavItem {
    label: string;
    to: string;
    search?: Record<string, string>;
    match: (pathname: string, search: Record<string, unknown>) => boolean;
    hint?: string;
}

export interface NavSection {
    title?: string;
    items: NavItem[];
}

export interface NavArea {
    key: string;
    label: string;
    icon: IconName;
    sections: NavSection[];
}

const BUILTIN_ENTITY_ORDER = ['route', 'query', 'view', 'job', 'queue', 'outgoing', 'host', 'user', 'ip', 'service', 'command'];

/**
 * The two-tier navigation model: one rail icon per area; the area's pages in
 * the subnav. Explore (signals + entities) leads; registered page groups
 * follow in registration order; ungrouped pages fold into Overview.
 */
export function buildAreas(boot: Bootstrap): NavArea[] {
    const areas: NavArea[] = [];

    const overview = boot.nav.find((g) => g.group === 'Overview');
    areas.push({
        key: 'Overview',
        label: 'Overview',
        icon: 'home',
        sections: [{
            items: (overview?.pages ?? [{ slug: 'dashboard', label: 'Dashboard' }]).map((p) => pageItem(p.slug, p.label)),
        }],
    });

    const builtins = boot.entities.filter((e) => !e.custom).sort((a, b) => BUILTIN_ENTITY_ORDER.indexOf(a.type) - BUILTIN_ENTITY_ORDER.indexOf(b.type));
    const custom = boot.entities.filter((e) => e.custom);
    const customGroups = new Map<string, typeof custom>();
    for (const e of custom) {
        const g = e.group ?? 'Custom';
        customGroups.set(g, [...(customGroups.get(g) ?? []), e]);
    }

    areas.push({
        key: 'Explore',
        label: 'Explore',
        icon: 'compass',
        sections: [
            {
                items: boot.explore.map((s) => ({
                    label: s.label,
                    to: `/explore/${s.signal}`,
                    match: (path) => path === `/explore/${s.signal}` || (s.signal === 'errors' && path.startsWith('/errors/')) || (s.signal === 'traces' && path.startsWith('/traces/')),
                })),
            },
            ...[...customGroups.entries()].map(([group, entities]) => ({
                title: group,
                items: entities.map((e) => entityItem(e.type, e.plural)),
            })),
            {
                title: 'Entities',
                items: builtins.map((e) => entityItem(e.type, e.plural)),
            },
        ],
    });

    for (const group of boot.nav) {
        if (group.group === 'Overview') continue;
        areas.push({
            key: group.group,
            label: group.group,
            icon: groupIcon(group.group),
            sections: [{ items: group.pages.map((p) => pageItem(p.slug, p.label)) }],
        });
    }

    return areas;
}

function pageItem(slug: string, label: string): NavItem {
    const to = slug === 'dashboard' ? '/' : `/p/${slug}`;
    return { label, to, match: (path) => path === to };
}

function entityItem(type: string, plural: string): NavItem {
    return {
        label: plural,
        to: `/entities/${encodeURIComponent(type)}`,
        match: (path) => path === `/entities/${encodeURIComponent(type)}` || path === `/entity/${encodeURIComponent(type)}`,
    };
}

/** The area the current location belongs to. */
export function activeArea(areas: NavArea[], pathname: string, search: Record<string, unknown>): NavArea | undefined {
    for (const area of areas) {
        for (const section of area.sections) {
            if (section.items.some((i) => i.match(pathname, search))) return area;
        }
    }
    if (pathname.startsWith('/entit') || pathname.startsWith('/explore') || pathname.startsWith('/errors') || pathname.startsWith('/traces')) {
        return areas.find((a) => a.key === 'Explore');
    }
    return areas[0];
}
