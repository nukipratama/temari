import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';

export type KoleksiTab = 'kartu' | 'rekor' | 'aksesori';

interface TabItem {
    id: KoleksiTab;
    label: string;
    count: string;
    href: string;
}

interface KoleksiTabsProps {
    active: KoleksiTab;
    counts: { kartu: number; rekor: number; aksesori: string };
    className?: string;
}

export default function KoleksiTabs({ active, counts, className }: Readonly<KoleksiTabsProps>) {
    const tabs: ReadonlyArray<TabItem> = [
        { id: 'kartu', label: 'Kartu', count: String(counts.kartu), href: '/kartu' },
        { id: 'rekor', label: 'Rekor', count: String(counts.rekor), href: '/rekor' },
        { id: 'aksesori', label: 'Aksesori', count: counts.aksesori, href: '/aksesori' },
    ];

    return (
        <nav aria-label="Sub-tab" className={cn('flex flex-wrap gap-1.5', className)}>
            {tabs.map((tab) => (
                <Link
                    key={tab.id}
                    href={tab.href}
                    className={cn(
                        'inline-flex items-center gap-2 rounded-full px-4 py-2.5 text-[13px] font-medium transition',
                        active === tab.id
                            ? 'bg-sky text-cream font-semibold'
                            : 'bg-transparent text-ink-2 hover:bg-sky/[0.06]',
                    )}
                >
                    {tab.label}
                    <span
                        className={cn(
                            'rounded-full px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-[0.06em]',
                            active === tab.id
                                ? 'bg-horizon/25 text-horizon'
                                : 'bg-sky/[0.08] text-ink-3',
                        )}
                    >
                        {tab.count}
                    </span>
                </Link>
            ))}
        </nav>
    );
}
