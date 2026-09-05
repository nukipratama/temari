import { Link } from '@inertiajs/react';

import { cn } from '@/lib/cn';

export type PlanRaceTab = 'plan' | 'race';

interface PlanRaceTabsProps {
    active: PlanRaceTab;
    className?: string;
}

const TABS: ReadonlyArray<{ id: PlanRaceTab; label: string; href: string }> = [
    { id: 'plan', label: 'schedule', href: '/plan' },
    { id: 'race', label: 'race goal', href: '/race' },
];

/** The schedule ⇄ race goal switcher: two real routes behind one pill toggle. */
export default function PlanRaceTabs({
    active,
    className,
}: Readonly<PlanRaceTabsProps>) {
    return (
        <nav className={cn('flex gap-1 rounded-full bg-muted p-1', className)}>
            {TABS.map((tab) => (
                <Link
                    key={tab.id}
                    href={tab.href}
                    aria-current={tab.id === active ? 'page' : undefined}
                    className={cn(
                        'focus-ring flex-1 rounded-full py-2 text-center text-[0.71875rem] font-bold text-foreground transition',
                        tab.id === active && 'bg-card shadow-e1',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
