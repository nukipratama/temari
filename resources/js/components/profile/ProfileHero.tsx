import type { ReactNode } from 'react';

import type { TimeInZone } from '@/components/profile/TimeInZoneBar';
import type { AnalysisPayload, Mood } from '@/types/inertia';

import TimeInZoneBar from '@/components/profile/TimeInZoneBar';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import MascotWatermark from '@/components/temari/MascotWatermark';
import { writingPose } from '@/components/temari/TemariMascot';
import Eyebrow from '@/components/ui/Eyebrow';
import { type IconComponent } from '@/components/ui/Icon';
import Skeleton from '@/components/ui/Skeleton';
import StatTile from '@/components/ui/StatTile';
import { formatShortDateId } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';

export interface HeroStat {
    icon: IconComponent;
    label: string;
    value: string;
    unit?: string;
}

/**
 * Temari's read on the athlete, split into the sentence that leads and the
 * rest. A single-sentence narration renders as the lead alone, matching
 * Today's `SessionVoice`.
 */
function leadSentence(text: string): readonly [string, string] {
    const match = /^(.+?[.!?])\s+(.*)$/s.exec(text);
    return match ? [match[1], match[2]] : [text, ''];
}

function ProfileVoice({ text }: Readonly<{ text: string }>) {
    const [lead, body] = leadSentence(stripEdgeQuotes(text));

    if (lead === '') {
        return null;
    }

    return (
        <>
            <p className="font-serif text-headline-sm text-foreground italic">
                {renderBold(lead)}
            </p>
            {body !== '' && (
                <p className="narration mt-2.5">{renderBold(body)}</p>
            )}
        </>
    );
}

/**
 * "What Temari says about you": Temari posed to today's vibe, her read on
 * the athlete, and the lifetime numbers behind it. Modeled on Today's
 * session section (`TodaySession.tsx`) rather than drawn as a bordered
 * card — no ring, no panel toning.
 */
export default function ProfileHero({
    mood,
    firstRunAt,
    voice,
    timeInZone,
    stats,
    action,
}: Readonly<{
    mood: Mood;
    firstRunAt: string | null;
    voice?: AnalysisPayload;
    timeInZone: TimeInZone | null | undefined;
    stats: ReadonlyArray<HeroStat>;
    action?: ReactNode;
}>) {
    return (
        <section className="relative isolate overflow-hidden">
            <MascotWatermark
                pose={writingPose(mood, voice)}
                className="-top-20 -right-14"
            />

            <Eyebrow as="h2" token="micro" tone="horizon-ink">
                what temari says about you
                {firstRunAt && ` · est. ${formatShortDateId(firstRunAt)}`}
            </Eyebrow>

            {voice && (
                <div className="mt-3">
                    <AnalysisStatus
                        analysis={voice}
                        inertiaReloadProps={['profileVoice']}
                        renderContent={(text) => <ProfileVoice text={text} />}
                    />
                </div>
            )}

            {action && <div className="mt-4">{action}</div>}

            <div className="mt-5 border-t border-dashed border-border" />
            {stats.length > 0 ? (
                <div className="mt-3.5 grid grid-cols-2 gap-2 min-[360px]:grid-cols-3">
                    {stats.map((stat) => (
                        <StatTile
                            key={stat.label}
                            icon={stat.icon}
                            label={stat.label}
                            value={stat.value}
                            delta={
                                stat.unit && (
                                    <span className="text-label-micro text-text-2">
                                        {stat.unit}
                                    </span>
                                )
                            }
                        />
                    ))}
                </div>
            ) : (
                <p className="mt-3.5 text-sm text-text-2">
                    no runs yet. sync your first one and your numbers show up
                    here.
                </p>
            )}

            {timeInZone === undefined ? (
                <div className="mt-6 border-t border-dashed border-border pt-6">
                    <Skeleton className="h-[52px] w-full rounded-lg" />
                </div>
            ) : (
                timeInZone && (
                    <div className="mt-6 border-t border-dashed border-border pt-6">
                        <TimeInZoneBar zones={timeInZone} />
                    </div>
                )
            )}
        </section>
    );
}
