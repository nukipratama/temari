import { cn } from '@/lib/utils';

const TONE = {
    neutral: 'text-ink',
    good: 'text-leaf-ink',
    watch: 'text-citrus-ink',
    high: 'text-ember',
} as const;

export type Tone = keyof typeof TONE;

interface StatTileProps {
    label: string;
    value: string;
    unit?: string;
    hint?: string;
    tone?: Tone;
    className?: string;
}

export function StatTile({
    label,
    value,
    unit,
    hint,
    tone = 'neutral',
    className,
}: Readonly<StatTileProps>) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1 overflow-hidden rounded-(--r-tile) bg-surface-sunken p-(--pad-tile)',
                className,
            )}
        >
            <span className="eyebrow text-[11px] text-ink-3">{label}</span>
            <span className="flex flex-wrap items-baseline gap-x-1.5">
                <span className={cn('num text-2xl leading-none', TONE[tone])}>
                    {value}
                </span>
                {unit ? (
                    <span className="text-xs font-semibold text-ink-3">
                        {unit}
                    </span>
                ) : null}
            </span>
            {hint ? (
                <span className="text-xs leading-snug text-ink-3">{hint}</span>
            ) : null}
        </div>
    );
}
