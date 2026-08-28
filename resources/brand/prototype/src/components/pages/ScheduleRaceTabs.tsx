import { cn } from '@/lib/utils';

type ScheduleRaceTab = 'schedule' | 'race';

export function ScheduleRaceTabs({
    active,
    onNavigate,
}: Readonly<{
    active: ScheduleRaceTab;
    onNavigate: (tab: ScheduleRaceTab) => void;
}>) {
    return (
        <nav className="mb-4 flex gap-1 rounded-full bg-muted p-1">
            <button
                type="button"
                onClick={() => onNavigate('schedule')}
                className={cn(
                    'flex-1 rounded-full py-2 text-center font-sans text-[11px] leading-[1.2] font-bold text-foreground',
                    active === 'schedule' && 'bg-card shadow-e1',
                )}
            >
                schedule
            </button>
            <button
                type="button"
                onClick={() => onNavigate('race')}
                className={cn(
                    'flex-1 rounded-full py-2 text-center font-sans text-[11px] leading-[1.2] font-bold text-foreground',
                    active === 'race' && 'bg-card shadow-e1',
                )}
            >
                race goal
            </button>
        </nav>
    );
}
