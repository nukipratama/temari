import { Link } from '@inertiajs/react';

import { cn } from '@/lib/cn';

export type HistoryTab = 'feed' | 'calendar';

interface HistoryNavProps {
    active: HistoryTab;
    className?: string;
}

const TABS: ReadonlyArray<{ id: HistoryTab; label: string; href: string }> = [
    { id: 'feed', label: 'Feed', href: '/history' },
    { id: 'calendar', label: 'Calendar', href: '/history?view=calendar' },
];

/** The Feed ⇄ Calendar switcher: two real routes behind one pill toggle. */
export default function HistoryNav({
    active,
    className,
}: Readonly<HistoryNavProps>) {
    return (
        <nav className={cn('flex gap-1 rounded-full bg-muted p-1', className)}>
            {TABS.map((tab) => (
                <Link
                    key={tab.id}
                    href={tab.href}
                    preserveScroll
                    className={cn(
                        'flex-1 rounded-full py-2 text-center text-[11.5px] font-bold text-foreground transition',
                        tab.id === active && 'bg-card shadow-e1',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
