import type { ReactNode } from 'react';

import type { TimeInZone } from '@/components/profile/TimeInZoneBar';
import type { AnalysisPayload, Mood } from '@/types/inertia';

import TimeInZoneBar from '@/components/profile/TimeInZoneBar';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import MascotPeek, {
    PANEL_PEEK_CLEARANCE,
} from '@/components/temari/MascotPeek';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon, IconComponent } from '@/components/ui/Icon';
import Skeleton from '@/components/ui/Skeleton';
import { SCROLL_FADE_MASK, useScrollFade } from '@/hooks/useScrollFade';
import { cn } from '@/lib/cn';
import { formatShortDateId } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';

export interface HeroStat {
    icon: IconComponent;
    label: string;
    value: string;
}

/**
 * "What Temari says about you": Temari posed to today's vibe, her read on
 * the athlete, where their training time went, and the lifetime numbers
 * behind it. A card-toned
 * panel with a horizon halo, as the prototype draws it — not one of the app's
 * sky-gradient heroes.
 */
export default function ProfileHero({
    mood,
    firstRunAt,
    memberSince,
    voice,
    timeInZone,
    stats,
    action,
}: Readonly<{
    mood: Mood;
    firstRunAt: string | null;
    memberSince: string | null;
    voice?: AnalysisPayload;
    timeInZone: TimeInZone | null | undefined;
    stats: ReadonlyArray<HeroStat>;
    action?: ReactNode;
}>) {
    const statRail = useScrollFade<HTMLDivElement>();

    return (
        <section className="relative overflow-hidden rounded-panel border-2 border-border-strong bg-card p-5 shadow-e1 ring-[1.5px] ring-horizon/45">
            <span
                aria-hidden
                className="pointer-events-none absolute -right-14 -top-14 size-[220px] rounded-full"
                style={{
                    background:
                        'radial-gradient(circle, color-mix(in oklab, var(--color-horizon) 22%, transparent) 0%, transparent 70%)',
                }}
            />

            <MascotPeek pose={mood} fit="panel" />

            <header
                className={cn(
                    'relative flex min-h-15 items-center gap-3.5',
                    PANEL_PEEK_CLEARANCE,
                )}
            >
                <div className="min-w-0">
                    <Eyebrow token="micro" tone="horizon-ink">
                        ★ What temari says about you
                    </Eyebrow>
                    {firstRunAt && (
                        <p className="mt-1.5 text-label-micro text-text-2">
                            Est. {formatShortDateId(firstRunAt)}
                        </p>
                    )}
                </div>
                {/* Reflow #9: the prototype reveals this block at 900px and draws
                    nothing in its place below, where the "Est." line above already
                    carries a date. */}
                {memberSince && (
                    <div className="ml-auto hidden flex-none text-right min-[900px]:block">
                        <Eyebrow token="micro" tone="ink-3">
                            With temari since
                        </Eyebrow>
                        <p className="mt-1 font-serif text-headline-sm text-foreground">
                            {formatShortDateId(memberSince)}
                        </p>
                    </div>
                )}
            </header>

            {voice && (
                <div className="relative mt-4">
                    <AnalysisStatus
                        analysis={voice}
                        inertiaReloadProps={['profileVoice']}
                        renderContent={(text) => (
                            <p className="narration">
                                {renderBold(stripEdgeQuotes(text))}
                            </p>
                        )}
                    />
                </div>
            )}

            {action && <div className="relative mt-4">{action}</div>}

            {timeInZone === undefined ? (
                <div className="relative mt-5">
                    <Skeleton className="h-[52px] w-full rounded-lg" />
                </div>
            ) : (
                timeInZone && (
                    <div className="relative mt-5">
                        <TimeInZoneBar zones={timeInZone} />
                    </div>
                )
            )}

            <div className="relative -mx-5 mt-5 border-t border-border-strong" />
            {stats.length > 0 ? (
                <div
                    ref={statRail.ref}
                    style={{
                        maskImage: statRail.faded
                            ? SCROLL_FADE_MASK
                            : undefined,
                    }}
                    className="relative -mx-5 flex gap-2 overflow-x-auto px-5 pb-0.5 pt-3.5 scrollbar-thin-fine"
                >
                    {stats.map((stat) => (
                        <div
                            key={stat.label}
                            className="grow shrink-0 basis-[108px] min-w-fit rounded-sm bg-muted px-2.5 py-3 text-center ring-1 ring-horizon/30"
                        >
                            <Icon
                                icon={stat.icon}
                                width={17}
                                height={17}
                                className="mx-auto mb-1.5 text-horizon-ink"
                                aria-hidden
                            />
                            <b className="block font-mono text-base font-bold tabular-nums text-foreground">
                                {stat.value}
                            </b>
                            <span className="mt-0.5 block text-label-micro text-text-2">
                                {stat.label}
                            </span>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="relative mt-3.5 text-sm text-text-2">
                    no runs yet. sync your first one and your numbers show up
                    here.
                </p>
            )}
        </section>
    );
}
