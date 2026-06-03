import Card from '@/components/ui/Card';
import LinkCard from '@/components/ui/LinkCard';
import { aktivitasUrl } from '@/lib/routes';

interface PrCardProps {
    category: string;
    time: string;
    setAt: string;
    activityId: number | null;
    runName?: string | null;
    size?: 'sm' | 'lg';
}

const TIME_CLASS = {
    sm: 'font-sans text-2xl font-bold leading-none tabular-nums tracking-[-0.02em] text-ink',
    lg: 'font-sans text-stat font-bold leading-none tabular-nums tracking-[-0.02em] text-ink',
} as const;

const GAP_CLASS = {
    sm: 'flex h-full flex-col gap-2',
    lg: 'flex h-full flex-col gap-3',
} as const;

export default function PrCard({
    category,
    time,
    setAt,
    activityId,
    runName,
    size = 'sm',
}: Readonly<PrCardProps>) {
    const body = (
        <>
            <div className="font-mono text-[11px] font-bold uppercase tracking-[0.16em] text-horizon-deep">
                {category}
            </div>
            <div className={TIME_CLASS[size]}>{time}</div>
            {runName && <div className="font-sans text-xs text-ink-2">{runName}</div>}
            <div className="font-mono font-bold text-[11px] uppercase tracking-[0.12em] text-ink-2">
                {setAt}
            </div>
        </>
    );

    if (activityId !== null) {
        return (
            <LinkCard href={aktivitasUrl({ activity_id: activityId })} padding={size === 'lg' ? 'lg' : 'md'} className={GAP_CLASS[size]}>
                {body}
            </LinkCard>
        );
    }
    return (
        <Card padding={size === 'lg' ? 'lg' : 'md'} className={GAP_CLASS[size]}>
            {body}
        </Card>
    );
}
