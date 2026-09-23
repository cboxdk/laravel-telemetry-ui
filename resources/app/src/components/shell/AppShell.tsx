import { Link, Outlet, useRouterState } from '@tanstack/react-router';
import { useEffect, useMemo, useState } from 'react';
import { useBootstrap, useManualRefresh, usePrefetchPage } from '../../api/hooks';
import type { Bootstrap } from '../../api/types';
import { parseSearch, scopeOf } from '../../lib/search';
import { seedRemembered } from '../../lib/state';
import { load, save } from '../../lib/storage';
import { useTheme } from '../../lib/theme';
import { CommandPalette } from '../CommandPalette';
import { Shortcuts } from '../Shortcuts';
import { BootContext } from '../DimensionValue';
import { DrawerStack } from '../drawer/DrawerStack';
import { ErrorState, Spinner } from '../States';
import { Icon } from '../Icon';
import { activeArea, buildAreas, type NavArea } from './nav';
import { TopBar } from './TopBar';

/**
 * The two-tier shell (Intercom model): an icon rail (one icon per area,
 * account at the foot) and the active area's contextual subnav. The rail is
 * minimised / hover-floating / pinned; the subnav collapses with ⌘. — both
 * remembered per viewer.
 */
export function AppShell() {
    const { data: boot, error, isLoading } = useBootstrap();

    useEffect(() => {
        if (boot) seedRemembered(boot.state);
    }, [boot]);

    if (isLoading && !boot) {
        return <div className="t-boot"><Spinner label="Loading dashboard…" /></div>;
    }

    if (!boot) {
        return <div className="t-boot"><ErrorState error={error} /></div>;
    }

    return (
        <BootContext.Provider value={boot}>
            <Shell boot={boot} />
        </BootContext.Provider>
    );
}

function Shell({ boot }: { boot: Bootstrap }) {
    const location = useRouterState({ select: (s) => s.location });
    const search = useMemo(() => parseSearch(location.searchStr), [location.searchStr]);
    const areas = useMemo(() => buildAreas(boot), [boot]);
    const area = activeArea(areas, location.pathname, search);

    const [pinned, setPinned] = useState<boolean>(() => load('railPinned', false));
    const [hover, setHover] = useState(false);
    const [collapsed, setCollapsed] = useState<boolean>(() => load('subnavCollapsed', false));
    // Phones: the subnav is a strip that opens as an overlay and closes on navigation.
    const narrow = useNarrow();
    const [mobileOpen, setMobileOpen] = useState(false);
    useEffect(() => setMobileOpen(false), [location.pathname]);
    const [paletteOpen, setPaletteOpen] = useState(false);
    useManualRefresh();

    useEffect(() => save('railPinned', pinned), [pinned]);
    useEffect(() => save('subnavCollapsed', collapsed), [collapsed]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const mod = e.metaKey || e.ctrlKey;
            if (mod && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setPaletteOpen((o) => !o);
            } else if (mod && e.key === '.') {
                e.preventDefault();
                setCollapsed((c) => !c);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const showSubnav = area !== undefined && area.sections.reduce((n, s) => n + s.items.length, 0) > 1;

    return (
        <div className={`t-app ${pinned && !narrow ? 'is-pinned' : ''} ${narrow ? 'is-narrow' : ''}`}>
            {/* Phones: the rail is a bottom tab bar — never a hover overlay. */}
            <Rail boot={boot} areas={areas} active={area} pinned={pinned && !narrow} expanded={!narrow && (pinned || hover)} onPin={() => setPinned((p) => !p)} onHover={setHover} scope={scopeOf(search)} />
            {showSubnav && area && !narrow && (
                collapsed
                    ? <button type="button" className="t-subnav-strip" onClick={() => setCollapsed(false)} title="Expand navigation (⌘.)"><span>{area.label}</span></button>
                    : <Subnav area={area} pathname={location.pathname} search={search} onCollapse={() => setCollapsed(true)} onSearch={() => setPaletteOpen(true)} scope={scopeOf(search)} />
            )}
            {showSubnav && area && narrow && mobileOpen && (
                <>
                    <div className="t-scrim" onClick={() => setMobileOpen(false)} aria-hidden="true" />
                    <Subnav area={area} overlay pathname={location.pathname} search={search} onCollapse={() => setMobileOpen(false)} onSearch={() => { setMobileOpen(false); setPaletteOpen(true); }} scope={scopeOf(search)} />
                </>
            )}
            <div className="t-main">
                {showSubnav && area && narrow && (
                    <button type="button" className="t-mobile-section" onClick={() => setMobileOpen(true)} aria-expanded={mobileOpen}>
                        <Icon name="list" size={14} />
                        <span className="t-dim">{area.label}</span>
                        <span className="t-mobile-section-cur">{area.sections.flatMap((s) => s.items).find((i) => i.match(location.pathname, search))?.label ?? ''}</span>
                        <Icon name="chevronDown" size={13} />
                    </button>
                )}
                <TopBar boot={boot} onPalette={() => setPaletteOpen(true)} />
                <main className="t-content canvas-gradient" id="main">
                    <Outlet />
                </main>
            </div>
            <DrawerStack boot={boot} />
            <CommandPalette boot={boot} areas={areas} open={paletteOpen} onClose={() => setPaletteOpen(false)} />
            <Shortcuts onPalette={() => setPaletteOpen(true)} />
        </div>
    );
}

function Rail({ boot, areas, active, pinned, expanded, onPin, onHover, scope }: {
    boot: Bootstrap;
    areas: NavArea[];
    active?: NavArea;
    pinned: boolean;
    expanded: boolean;
    onPin: () => void;
    onHover: (h: boolean) => void;
    scope: Record<string, string>;
}) {
    const [theme, toggleTheme] = useTheme();
    const initials = (boot.user?.name ?? boot.app.name).split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase();

    return (
        <>
            {pinned && <div className="t-rail-spacer" aria-hidden="true" />}
            <nav
                className={`t-rail ${expanded ? 'is-expanded' : ''} ${pinned ? 'is-pinned' : ''}`}
                aria-label="Areas"
                onMouseEnter={() => onHover(true)}
                onMouseLeave={() => onHover(false)}
            >
                <div className="t-rail-head">
                    <Link to="/" search={scope as never} className="t-rail-logo" title={boot.app.name}>
                        {boot.app.logo ? <img src={boot.app.logo} alt="" /> : <span>{boot.app.name.charAt(0)}</span>}
                    </Link>
                    <span className="t-rail-brand">{boot.app.name}</span>
                    <button type="button" className={`t-rail-pin ${pinned ? 'is-on' : ''}`} onClick={onPin} title={pinned ? 'Unpin navigation' : 'Pin navigation'}>
                        <Icon name="pin" size={14} />
                    </button>
                </div>
                <div className="t-rail-items">
                    {areas.map((area) => {
                        const first = area.sections.flatMap((s) => s.items)[0];
                        return (
                            <Link
                                key={area.key}
                                to={first?.to ?? '/'}
                                search={scope as never}
                                className={`t-rail-item ${active?.key === area.key ? 'is-active' : ''}`}
                                title={area.label}
                            >
                                <Icon name={area.icon} size={19} />
                                <span className="t-rail-label">{area.label}</span>
                            </Link>
                        );
                    })}
                </div>
                <div className="t-rail-foot">
                    {boot.navLinks.map((link) => (
                        <a key={link.key} href={link.url} className="t-rail-item" title={link.label}>
                            <Icon name={link.icon ?? 'link'} size={18} />
                            <span className="t-rail-label">{link.label}</span>
                        </a>
                    ))}
                    <button type="button" className="t-rail-item" onClick={toggleTheme} title={theme === 'dark' ? 'Light theme' : 'Dark theme'}>
                        <Icon name={theme === 'dark' ? 'sun' : 'moon'} size={18} />
                        <span className="t-rail-label">{theme === 'dark' ? 'Light theme' : 'Dark theme'}</span>
                    </button>
                    <div className="t-rail-item t-rail-account" title={boot.user?.email ?? boot.user?.name ?? 'Signed in'}>
                        <span className="t-avatar">{initials}</span>
                        <span className="t-rail-label">{boot.user?.name ?? 'Guest'}</span>
                    </div>
                </div>
            </nav>
        </>
    );
}

function useNarrow(): boolean {
    const query = '(max-width: 760px)';
    const [narrow, setNarrow] = useState(() => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(query).matches);
    useEffect(() => {
        if (typeof window.matchMedia !== 'function') return;
        const mq = window.matchMedia(query);
        const on = () => setNarrow(mq.matches);
        mq.addEventListener('change', on);
        return () => mq.removeEventListener('change', on);
    }, []);
    return narrow;
}

function Subnav({ area, overlay, pathname, search, onCollapse, onSearch, scope }: {
    area: NavArea;
    overlay?: boolean;
    pathname: string;
    search: Record<string, unknown>;
    onCollapse: () => void;
    onSearch: () => void;
    scope: Record<string, string>;
}) {
    const prefetchPage = usePrefetchPage();

    return (
        <nav className={`t-subnav ${overlay ? 'is-overlay' : ''}`} aria-label={area.label}>
            <div className="t-subnav-head">
                <h2>{area.label}</h2>
                <button type="button" className="t-iconbtn" onClick={onCollapse} title="Collapse (⌘.)"><Icon name="panel" size={15} /></button>
            </div>
            <button type="button" className="t-subnav-search" onClick={onSearch}>
                <Icon name="search" size={13} />
                <span>Search</span>
                <kbd>⌘K</kbd>
            </button>
            <div className="t-subnav-scroll">
                {area.sections.map((section, i) => (
                    <div key={section.title ?? i} className="t-subnav-section">
                        {section.title && <div className="t-eyebrow t-subnav-title">{section.title}</div>}
                        {section.items.map((item) => (
                            <Link
                                key={item.to}
                                to={item.to}
                                search={{ ...scope, ...(item.search ?? {}) } as never}
                                className={`t-subnav-item ${item.match(pathname, search) ? 'is-active' : ''}`}
                                // Warm the page's panels while the pointer is still travelling.
                                onMouseEnter={() => { const slug = /^\/p\/(.+)$/.exec(item.to)?.[1] ?? (item.to === '/' ? 'dashboard' : null); if (slug) prefetchPage(slug); }}
                            >
                                {item.label}
                            </Link>
                        ))}
                    </div>
                ))}
            </div>
        </nav>
    );
}
