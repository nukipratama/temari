import type { StreamSummaryLap } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import { SCROLL_FADE_MASK, useScrollFade } from '@/hooks/useScrollFade';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatKm } from '@/lib/pace';
import { paceScale, paceSecOf } from '@/lib/splits';

/**
 * The watch's own laps, one card each, scrolled sideways as the prototype's
 * `LapsCarousel` draws them — no paging buttons, just a native overflow scroll
 * with the scrollbar hidden.
 */
export default function LapsCarousel({
    laps,
    className,
}: Readonly<{ laps: StreamSummaryLap[]; className?: string }>) {
    const { fastest } = paceScale(laps);
    const rail = useScrollFade<HTMLUListElement>();

    return (
        <section className={className}>
            <Eyebrow token="micro" tone="ink-2" className="mb-2 px-0.5">
                Laps
            </Eyebrow>
            <ul
                ref={rail.ref}
                style={{
                    maskImage: rail.faded ? SCROLL_FADE_MASK : undefined,
                }}
                className="-mx-4 flex list-none gap-2.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            >
                {laps.map((lap) => {
                    const isFastest =
                        fastest != null && paceSecOf(lap) === fastest;
                    return (
                        <li
                            key={`lap-${lap.lap}`}
                            className={cn(
                                'flex w-32 flex-none flex-col gap-2 rounded-md border p-3.5 shadow-e1',
                                isFastest
                                    ? 'border-horizon-ink bg-horizon/10'
                                    : 'border-border-strong bg-card',
                            )}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-label-micro text-text-2">
                                    Lap {lap.lap}
                                </span>
                                {isFastest && (
                                    <Icon
                                        icon="mdi:lightning-bolt"
                                        width={12}
                                        height={12}
                                        aria-hidden
                                        className="flex-none fill-current text-icon-accent"
                                    />
                                )}
                            </div>
                            <b className="font-mono text-xl font-bold tabular-nums leading-tight text-foreground">
                                {lap.pace}
                            </b>
                            <span className="font-sans text-xs text-text-2">
                                {formatKm(lap.distance_m, 2)} km ·{' '}
                                {formatDurationHMS(lap.elapsed_sec)}
                            </span>
                            <div className="mt-1 flex items-center gap-2.5 font-mono text-xs tabular-nums text-text-2">
                                <span>♡ {lap.avg_hr ?? '—'}</span>
                                <span className="flex items-center gap-1">
                                    <Icon
                                        icon="mdi:shoe-print"
                                        width={10}
                                        height={10}
                                        aria-hidden
                                    />
                                    {lap.avg_cadence_spm ?? '—'}
                                </span>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
