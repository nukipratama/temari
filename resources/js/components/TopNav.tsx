import { Link, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useCallback, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import BrandMark from '@/components/BrandMark';
import StravaSyncBadge from '@/components/StravaSyncBadge';
import UserAvatar from '@/components/UserAvatar';
import { useDismissable } from '@/hooks/useDismissable';
import type { SharedProps } from '@/types/inertia';

type TabId = 'hari-ini' | 'koleksi' | 'riwayat' | 'aku';

interface NavItem {
    id: TabId;
    label: string;
    href: string;
    prefixes: ReadonlyArray<string>;
}

const ITEMS: ReadonlyArray<NavItem> = [
    { id: 'hari-ini', label: 'Hari Ini', href: '/', prefixes: ['/'] },
    { id: 'koleksi', label: 'Koleksi', href: '/kartu', prefixes: ['/koleksi', '/kartu', '/rekor'] },
    { id: 'riwayat', label: 'Riwayat', href: '/aktivitas', prefixes: ['/riwayat', '/aktivitas', '/kalender'] },
    { id: 'aku', label: 'Aku', href: '/profil', prefixes: ['/aku', '/profil'] },
];

export function activeTabFromUrl(url: string): TabId | null {
    const path = url.split('?')[0];
    if (path === '/') return 'hari-ini';
    for (const item of ITEMS) {
        if (item.id === 'hari-ini') continue;
        if (item.prefixes.some((p) => path === p || path.startsWith(`${p}/`))) {
            return item.id;
        }
    }
    return null;
}

export default function TopNav() {
    const { url, props } = usePage<SharedProps>();
    const active = activeTabFromUrl(url);
    const user = props.auth.user;
    const stravaSync = props.stravaSync ?? null;

    return (
        <header className="hidden bg-cream-deep lg:block">
            <div className="flex w-full items-center justify-between px-10 py-[18px]">
                <div className="flex items-center gap-12">
                    <Link href="/" aria-label="Beranda">
                        <BrandMark />
                    </Link>
                    <nav aria-label="Primary" className="flex items-center gap-1">
                        {ITEMS.map((item) => (
                            <TabLink key={item.id} item={item} isActive={active === item.id} />
                        ))}
                    </nav>
                </div>
                <div className="flex items-center gap-3.5">
                    <StravaSyncBadge sync={stravaSync} />
                    {user && <UserMenu name={user.name} avatarUrl={user.avatar_url} />}
                </div>
            </div>
        </header>
    );
}

function TabLink({ item, isActive }: Readonly<{ item: NavItem; isActive: boolean }>) {
    return (
        <Link
            href={item.href}
            className={cn(
                'relative font-mono text-sm tracking-[0.02em] transition',
                'px-[18px] py-2.5',
                isActive ? 'text-ink' : 'text-ink-3 hover:text-ink-2',
            )}
        >
            {item.label}
            {isActive && (
                <span
                    aria-hidden
                    className="absolute inset-x-[18px] -bottom-[19px] h-0.5 bg-horizon"
                />
            )}
        </Link>
    );
}

function UserMenu({ name, avatarUrl }: Readonly<{ name: string; avatarUrl: string | null }>) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const close = useCallback(() => setOpen(false), []);
    useDismissable(open, containerRef, close);

    function handleLogout() {
        setOpen(false);
        router.post('/logout');
    }

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={`Buka menu ${name}`}
                className="flex h-9 w-9 items-center justify-center rounded-full ring-2 ring-cream-deep transition hover:ring-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 focus-visible:ring-offset-cream"
            >
                <UserAvatar name={name} avatarUrl={avatarUrl} size="md" />
            </button>
            {open && (
                <div
                    role="menu"
                    className="absolute right-0 top-[calc(100%+10px)] z-40 w-52 overflow-hidden rounded-2xl border border-cream-deep bg-cream shadow-lg"
                >
                    <div className="border-b border-cream-deep px-4 py-3">
                        <div className="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-3">
                            Masuk sebagai
                        </div>
                        <div className="mt-0.5 truncate font-sans text-sm font-medium text-ink">
                            {name}
                        </div>
                    </div>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={handleLogout}
                        className="flex w-full items-center gap-2.5 px-4 py-2.5 text-left font-sans text-sm text-ink transition hover:bg-cream-deep"
                    >
                        <Icon icon="mdi:logout" width={16} height={16} aria-hidden className="text-ink-3" />
                        Keluar
                    </button>
                </div>
            )}
        </div>
    );
}
