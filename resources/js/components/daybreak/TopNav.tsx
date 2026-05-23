import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import BrandMark from '@/components/BrandMark';
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

    return (
        <header className="hidden border-b border-cream-deep bg-cream lg:block">
            <div className="mx-auto flex max-w-7xl items-center justify-between px-10 py-[18px]">
                <div className="flex items-center gap-12">
                    <Link href="/" aria-label="Beranda">
                        <BrandMark size="compact" />
                    </Link>
                    <nav aria-label="Primary" className="flex items-center gap-1">
                        {ITEMS.map((item) => (
                            <TabLink key={item.id} item={item} isActive={active === item.id} />
                        ))}
                    </nav>
                </div>
                <div className="flex items-center gap-3.5">
                    <SyncPill />
                    {user && <UserAvatar name={user.name} avatarUrl={user.avatar_url} />}
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
                'relative font-display text-[19px] italic tracking-[-0.005em] transition',
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

function SyncPill() {
    return (
        <div className="hidden items-center gap-2 rounded-full bg-sky/[0.06] px-3.5 py-2 font-mono text-xs uppercase tracking-[0.1em] text-ink-3 md:inline-flex">
            <span aria-hidden className="h-1.5 w-1.5 rounded-full bg-leaf" />
            Strava synced
        </div>
    );
}

function UserAvatar({ name, avatarUrl }: Readonly<{ name: string; avatarUrl: string | null }>) {
    if (avatarUrl) {
        return (
            <img
                src={avatarUrl}
                alt={name}
                className="h-9 w-9 rounded-full object-cover ring-2 ring-cream-deep"
            />
        );
    }
    return (
        <div
            className="flex h-9 w-9 items-center justify-center rounded-full bg-horizon font-display text-[17px] font-semibold italic text-sky"
            aria-label={name}
        >
            {name.charAt(0).toUpperCase()}
        </div>
    );
}
