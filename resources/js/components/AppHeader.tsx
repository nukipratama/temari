import { Link, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import BrandMark from '@/components/BrandMark';
import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types/inertia';

const LINKS = [
    { route: 'dashboard', href: '/dashboard', icon: 'mdi:home-outline', label: 'Beranda' },
    { route: 'runs.index', href: '/runs', icon: 'mdi:run-fast', label: 'Aktivitas' },
    { route: 'cards.index', href: '/cards', icon: 'mdi:cards-outline', label: 'Kartu' },
    { route: 'progress', href: '/progress', icon: 'mdi:chart-line', label: 'Catatan' },
];

export default function AppHeader() {
    const { props, url } = usePage<SharedProps>();
    const user = props.auth.user;

    const logout = () => router.post('/logout');

    return (
        <header className="border-b border-line bg-surface-elev/60 backdrop-blur dark:border-line-dark dark:bg-surface-dark-elev/60">
            <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
                <div className="flex items-center gap-6">
                    <Link href="/dashboard">
                        <BrandMark size="compact" />
                    </Link>
                    <nav className="hidden gap-1 sm:flex">
                        {LINKS.map((link) => {
                            const active = url === link.href || url.startsWith(`${link.href}/`);
                            return (
                                <Link
                                    key={link.route}
                                    href={link.href}
                                    className={cn(
                                        'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                        active
                                            ? 'bg-brand-500/15 text-brand-700 dark:text-brand-300'
                                            : 'text-ink-soft hover:bg-surface hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-dark dark:hover:text-ink-dark',
                                    )}
                                >
                                    <Icon icon={link.icon} width={16} height={16} aria-hidden />
                                    <span>{link.label}</span>
                                </Link>
                            );
                        })}
                    </nav>
                </div>

                {user !== null && (
                    <div className="flex items-center gap-3">
                        <div className="hidden text-right sm:block">
                            <div className="text-sm font-medium leading-tight text-ink dark:text-ink-dark">{user.name}</div>
                        </div>
                        {user.avatar_url ? (
                            <img src={user.avatar_url} alt="" className="h-9 w-9 rounded-full ring-2 ring-line dark:ring-line-dark" />
                        ) : (
                            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-500/15 text-sm font-semibold text-brand-600 dark:text-brand-400">
                                {user.name.charAt(0).toUpperCase()}
                            </div>
                        )}
                        <button
                            type="button"
                            onClick={logout}
                            className="rounded-lg border border-line px-3 py-1.5 text-sm text-ink transition hover:border-ink-soft dark:border-line-dark dark:text-ink-dark dark:hover:border-ink-soft-dark"
                        >
                            Keluar
                        </button>
                    </div>
                )}
            </div>
        </header>
    );
}
