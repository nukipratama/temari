import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import BrandMark from '@/components/BrandMark';
import { useSidebar } from '@/contexts/SidebarContext';
import { cn } from '@/lib/cn';
import type { AuthUser, SharedProps } from '@/types/inertia';

const LINKS: ReadonlyArray<{ route: string; href: string; icon: string; label: string }> = [
    { route: 'dashboard', href: '/dashboard', icon: 'mdi:home-outline', label: 'Beranda' },
    { route: 'runs.index', href: '/runs', icon: 'mdi:run-fast', label: 'Aktivitas' },
    { route: 'cards.index', href: '/cards', icon: 'mdi:cards-outline', label: 'Kartu' },
    { route: 'progress', href: '/progress', icon: 'mdi:chart-line', label: 'Catatan' },
];

/**
 * Renders twice — as a persistent `<aside>` on `lg+` and inside a native
 * `<dialog>` drawer on `< lg`. Same content (BrandMark, nav links, user
 * chip with dropdown), different containers.
 */
export default function Sidebar() {
    const { dialogRef, close } = useSidebar();
    const { props, url } = usePage<SharedProps>();
    const user = props.auth.user;

    return (
        <>
            <aside
                aria-label="Main navigation"
                className="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-20 lg:flex lg:w-64 lg:flex-col lg:border-r lg:border-line lg:bg-surface-elev dark:lg:border-line-dark dark:lg:bg-surface-dark-elev"
            >
                <SidebarContent url={url} user={user} onNavigate={() => {}} />
            </aside>

            <dialog
                ref={dialogRef}
                aria-label="Main navigation"
                className="sidebar-drawer m-0 max-h-screen w-72 max-w-[85vw] bg-surface-elev p-0 text-ink shadow-xl dark:bg-surface-dark-elev dark:text-ink-dark lg:hidden"
                onClose={close}
            >
                <SidebarContent url={url} user={user} onNavigate={close} />
            </dialog>
        </>
    );
}

function SidebarContent({
    url,
    user,
    onNavigate,
}: Readonly<{ url: string; user: AuthUser | null; onNavigate: () => void }>) {
    return (
        <div className="flex h-full flex-col">
            <div className="border-b border-line px-5 py-5 dark:border-line-dark">
                <Link href="/dashboard" onClick={onNavigate} aria-label="Beranda">
                    <BrandMark size="compact" />
                </Link>
            </div>

            <nav className="flex-1 px-3 py-4">
                <ul className="space-y-1">
                    {LINKS.map((link) => {
                        const active = url === link.href || url.startsWith(`${link.href}/`);
                        return (
                            <li key={link.route}>
                                <Link
                                    href={link.href}
                                    onClick={onNavigate}
                                    className={cn(
                                        'flex items-center gap-3 rounded-lg border-l-4 px-3 py-2.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
                                        active
                                            ? 'border-brand-500 bg-brand-500/10 text-brand-700 dark:text-brand-300'
                                            : 'border-transparent text-ink-soft hover:bg-line/40 hover:text-ink dark:text-ink-soft-dark dark:hover:bg-line-dark dark:hover:text-ink-dark',
                                    )}
                                >
                                    <Icon icon={link.icon} width={18} height={18} aria-hidden />
                                    <span>{link.label}</span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            {user !== null && <UserChip user={user} onNavigate={onNavigate} />}
        </div>
    );
}

function UserChip({ user, onNavigate }: Readonly<{ user: AuthUser; onNavigate: () => void }>) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const handler = (e: MouseEvent) => {
            if (containerRef.current !== null && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        const escape = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', handler);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('mousedown', handler);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    const logout = () => {
        setOpen(false);
        onNavigate();
        router.post('/logout');
    };

    return (
        <div ref={containerRef} className="relative border-t border-line p-3 dark:border-line-dark">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-line/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:hover:bg-line-dark"
            >
                {user.avatar_url !== null ? (
                    <img
                        src={user.avatar_url}
                        alt={user.name}
                        className="h-10 w-10 rounded-full ring-2 ring-line dark:ring-line-dark"
                    />
                ) : (
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-500/15 text-sm font-semibold text-brand-600 dark:text-brand-400">
                        {user.name.charAt(0).toUpperCase()}
                    </div>
                )}
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium text-ink dark:text-ink-dark">{user.name}</div>
                    <div className="text-xs text-ink-meta dark:text-ink-meta-dark">Tap untuk menu</div>
                </div>
                <Icon icon="mdi:chevron-up" width={18} height={18} className={cn('transition', open ? 'rotate-0' : 'rotate-180')} aria-hidden />
            </button>

            {open && (
                <MenuList>
                    <MenuItem href="/profile" icon="mdi:account-outline" label="Profil" onSelect={onNavigate} />
                    <MenuItem href="/settings" icon="mdi:cog-outline" label="Pengaturan" onSelect={onNavigate} />
                    <MenuButton onClick={logout} icon="mdi:logout" label="Keluar" />
                </MenuList>
            )}
        </div>
    );
}

function MenuList({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <div
            role="menu"
            className="absolute bottom-full left-3 right-3 mb-2 overflow-hidden rounded-xl border border-line bg-surface-elev shadow-lg dark:border-line-dark dark:bg-surface-dark-elev"
        >
            {children}
        </div>
    );
}

function MenuItem({
    href,
    icon,
    label,
    onSelect,
}: Readonly<{ href: string; icon: string; label: string; onSelect: () => void }>) {
    return (
        <Link
            href={href}
            role="menuitem"
            onClick={onSelect}
            className="flex items-center gap-3 px-3 py-2.5 text-sm text-ink transition hover:bg-line/40 focus-visible:bg-line/40 focus-visible:outline-none dark:text-ink-dark dark:hover:bg-line-dark"
        >
            <Icon icon={icon} width={18} height={18} aria-hidden />
            {label}
        </Link>
    );
}

function MenuButton({ onClick, icon, label }: Readonly<{ onClick: () => void; icon: string; label: string }>) {
    return (
        <button
            type="button"
            role="menuitem"
            onClick={onClick}
            className="flex w-full items-center gap-3 border-t border-line px-3 py-2.5 text-left text-sm text-ink transition hover:bg-line/40 focus-visible:bg-line/40 focus-visible:outline-none dark:border-line-dark dark:text-ink-dark dark:hover:bg-line-dark"
        >
            <Icon icon={icon} width={18} height={18} aria-hidden />
            {label}
        </button>
    );
}
