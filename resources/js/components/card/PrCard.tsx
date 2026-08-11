import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import LinkCard from '@/components/ui/LinkCard';
import { activityUrl } from '@/lib/routes';

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
    sm: 'flex h-full flex-col gap-2 shadow-sm',
    lg: 'flex h-full flex-col gap-3 shadow-sm',
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
            <Eyebrow token="hero" tone="horizon-deep">
                {category}
            </Eyebrow>
            <div className={TIME_CLASS[size]}>{time}</div>
            {runName && (
                <div className="font-sans text-xs text-ink-2">{runName}</div>
            )}
            <Eyebrow token="micro" tone="ink-2">
                {setAt}
            </Eyebrow>
        </>
    );

    if (activityId !== null) {
        return (
            <LinkCard
                href={activityUrl({ activity_id: activityId })}
                padding={size === 'lg' ? 'lg' : 'md'}
                className={GAP_CLASS[size]}
            >
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
