import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { armPendingTab } from '@/lib/pendingTab';

export type HistoryTab = 'feed' | 'calendar';

interface HistoryNavProps {
    active: HistoryTab;
    className?: string;
}

const TABS: ReadonlyArray<{ id: HistoryTab; label: string; href: string }> = [
    { id: 'feed', label: 'feed', href: '/history' },
    { id: 'calendar', label: 'calendar', href: '/history?view=calendar' },
];

/** The feed ⇄ calendar switcher: two real routes behind one pill toggle. */
export default function HistoryNav({
    active,
    className,
}: Readonly<HistoryNavProps>) {
    const { component } = usePage<SharedProps>();

    return (
        <nav className={cn('flex gap-1 rounded-full bg-muted p-1', className)}>
            {TABS.map((tab) => (
                <Link
                    key={tab.id}
                    href={tab.href}
                    preserveScroll
                    onClick={
                        tab.id === active
                            ? undefined
                            : () => armPendingTab('history', component)
                    }
                    className={cn(
                        'flex-1 rounded-full py-2 text-center text-[0.71875rem] font-bold text-foreground transition',
                        tab.id === active && 'bg-card shadow-e1',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
