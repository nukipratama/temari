import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';

export type RiwayatTab = 'linimasa' | 'kalender';

interface RiwayatTabsProps {
    active: RiwayatTab;
    className?: string;
}

const TABS = [
    { id: 'linimasa' as const, label: 'Linimasa', href: '/aktivitas' },
    { id: 'kalender' as const, label: 'Kalender', href: '/kalender' },
];

export default function RiwayatTabs({ active, className }: Readonly<RiwayatTabsProps>) {
    return (
        <nav aria-label="Sub-tab" className={cn('flex flex-wrap gap-1.5', className)}>
            {TABS.map((tab) => (
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
                </Link>
            ))}
        </nav>
    );
}
