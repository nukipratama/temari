import { Footprints, Zap } from 'lucide-react';

import type { StreamSummaryLap } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import StatTile from '@/components/ui/StatTile';
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
    const { ref: setRail, faded } = useScrollFade<HTMLUListElement>();

    return (
        <section className={className}>
            <Eyebrow as="h2" token="small" tone="ink-2" className="mb-2 px-0.5">
                Laps
            </Eyebrow>
            <ul
                ref={setRail}
                style={{
                    maskImage: faded ? SCROLL_FADE_MASK : undefined,
                }}
                className="-mx-4 flex list-none gap-2.5 overflow-x-auto px-4 pb-1 scrollbar-none pointer-fine:scrollbar-thin pointer-fine:scrollbar-thumb-muted pointer-fine:scrollbar-track-transparent"
            >
                {laps.map((lap) => {
                    const isFastest =
                        fastest != null && paceSecOf(lap) === fastest;
                    return (
                        <StatTile
                            key={`lap-${lap.lap}`}
                            as="li"
                            icon={isFastest ? Zap : undefined}
                            label={`lap ${lap.lap}`}
                            value={lap.pace}
                            sub={`${formatKm(lap.distance_m, 2)} km · ${formatDurationHMS(lap.elapsed_sec)}`}
                            className={cn(
                                'w-32 flex-none',
                                isFastest &&
                                    'ring-[1.5px] ring-inset ring-icon-accent',
                            )}
                        >
                            <div className="mt-2 flex items-center gap-2.5 font-mono text-xs tabular-nums text-text-2">
                                <span>♡ {lap.avg_hr ?? '—'}</span>
                                <span className="flex items-center gap-1">
                                    <Icon
                                        icon={Footprints}
                                        width={10}
                                        height={10}
                                        aria-hidden
                                    />
                                    {lap.avg_cadence_spm ?? '—'}
                                </span>
                            </div>
                        </StatTile>
                    );
                })}
            </ul>
        </section>
    );
}
