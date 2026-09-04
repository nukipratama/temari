import type { ReactNode } from 'react';

import type { TimeInZone } from '@/components/profile/TimeInZoneBar';
import type { AnalysisPayload } from '@/types/inertia';

import TimeInZoneBar from '@/components/profile/TimeInZoneBar';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import FaceIcon from '@/components/temari/FaceIcon';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import { SCROLL_FADE_MASK, useScrollFade } from '@/hooks/useScrollFade';
import { formatShortDateId } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';

export interface HeroStat {
    icon: string;
    label: string;
    value: string;
}

/**
 * "What Temari says about you": the mascot, her read on the athlete, where
 * their training time went, and the lifetime numbers behind it. A card-toned
 * panel with a horizon halo, as the prototype draws it — not one of the app's
 * sky-gradient heroes.
 */
export default function ProfileHero({
    firstRunAt,
    memberSince,
    voice,
    timeInZone,
    stats,
    action,
}: Readonly<{
    firstRunAt: string | null;
    memberSince: string | null;
    voice?: AnalysisPayload;
    timeInZone: TimeInZone | null;
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

            <header className="relative flex items-center gap-3.5">
                <div
                    className="flex-none"
                    style={{
                        filter: 'drop-shadow(0 0 10px color-mix(in oklab, var(--color-horizon) 45%, transparent))',
                    }}
                >
                    <FaceIcon size={64} ring="var(--color-leaf)" />
                </div>
                <div className="min-w-0">
                    <Eyebrow token="micro" tone="horizon-ink">
                        ★ What Temari says about you
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
                            With Temari since
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
                        showTimestamp={false}
                        renderContent={(text) => (
                            <p className="font-serif text-sm italic leading-relaxed text-foreground">
                                {renderBold(stripEdgeQuotes(text))}
                            </p>
                        )}
                    />
                </div>
            )}

            {action && <div className="relative mt-4">{action}</div>}

            {timeInZone && (
                <div className="relative mt-5">
                    <TimeInZoneBar zones={timeInZone} />
                </div>
            )}

            <div className="relative -mx-5 mt-5 border-t border-border-strong" />
            <div
                ref={statRail.ref}
                style={{
                    maskImage: statRail.faded ? SCROLL_FADE_MASK : undefined,
                }}
                className="relative -mx-5 flex gap-2 overflow-x-auto px-5 pb-0.5 pt-3.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
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
        </section>
    );
}
