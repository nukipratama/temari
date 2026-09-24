import { ArrowDown } from 'lucide-react';

import type { BriefingResult, WeekPlanDay } from '@/types/inertia';

import { AskedRanResult, ChangeRow } from '@/components/plan/DeltaPair';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { renderNarration } from '@/components/temari/Citation';
import MascotWatermark from '@/components/temari/MascotWatermark';
import { writingPose } from '@/components/temari/TemariMascot';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import {
    clampSummary,
    easedFromDelta,
    judgedDayResult,
    paceEaseDelta,
    paceLabel,
    prescriptionWhy,
    SESSION_TYPE_LABEL,
    sessionPurpose,
    sessionShape,
} from '@/lib/plan';
import { stripEdgeQuotes } from '@/lib/richText';

/**
 * Paragraph breaks when the narrator honoured them, otherwise the opening
 * sentence. A decimal never ends a sentence, so requiring whitespace after the
 * stop keeps "25.5 km" intact.
 */
function leadAndBody(text: string): readonly [string, string] {
    const paragraphs = text
        .split(/\n\n+/)
        .map((part) => part.trim())
        .filter(Boolean);

    if (paragraphs.length > 1) {
        const [first, ...rest] = paragraphs;
        return [first, rest.join(' ')];
    }

    const single = paragraphs[0] ?? '';
    const sentence = /^(.+?[.!?])\s+(.*)$/s.exec(single);

    return sentence ? [sentence[1], sentence[2]] : [single, ''];
}

/**
 * Temari's read on today, split into the line that leads and the rest.
 */
function SessionVoice({
    text,
    drawnAnchors,
}: Readonly<{ text: string; drawnAnchors: ReadonlySet<string> }>) {
    const [lead, body] = leadAndBody(text);

    if (lead === '') {
        return null;
    }

    return (
        <>
            <p className="narration font-semibold">
                {renderNarration(stripEdgeQuotes(lead), drawnAnchors)}
            </p>
            {body !== '' && (
                <p className="narration-dense mt-2.5">
                    {renderNarration(body, drawnAnchors)}
                </p>
            )}
        </>
    );
}

/**
 * What today asks for, above the voice describing it: the session, its
 * distance and the pace it is run at. A recorded ease is the session itself,
 * with what it replaced and why beneath it; a step-down that was never
 * recorded stays a marked modification beside the prescription. See
 * `docs/decisions/the-eased-session-leads.md`.
 */
function TodayPrescription({ day }: Readonly<{ day: WeekPlanDay }>) {
    const judged = judgedDayResult(day);
    const pace = judged === null ? paceLabel(day) : null;
    const sessionDelta = day.eased_from
        ? easedFromDelta(day.eased_from, day)
        : null;
    const paceDelta = day.pace_eased_from
        ? paceEaseDelta(day.pace_eased_from, day)
        : null;
    const parts = [SESSION_TYPE_LABEL[day.session_type] ?? day.session_type];
    if (day.session_type !== 'rest' && judged === null) {
        parts.push(`${day.distance_km} km`);
        if (pace !== null) {
            parts.push(pace);
        }
    }
    const shape = judged === null ? sessionShape(day.segments) : null;
    const purpose = judged === null ? sessionPurpose(day) : null;
    const doseWhy = judged === null ? prescriptionWhy(day) : null;

    return (
        <div
            id="anchor-session-today"
            className="mt-2 rounded-lg bg-muted/40 px-3 py-2.5"
        >
            <p className="text-sm font-semibold text-foreground">
                {parts.join(' · ')}
            </p>
            {shape && (
                <p className="mt-1 text-xs leading-relaxed text-text-2">
                    {shape}
                </p>
            )}
            {purpose && (
                <p className="mt-1.5 text-xs leading-relaxed text-foreground">
                    {purpose}
                    {doseWhy && (
                        <span className="text-text-2 italic"> {doseWhy}</span>
                    )}
                </p>
            )}
            {judged !== null && (
                <AskedRanResult
                    askedKm={judged.askedKm}
                    askedPace={judged.askedPace}
                    ranKm={judged.ranKm}
                    ranPace={judged.ranPace}
                />
            )}
            {sessionDelta && (
                <div className="mt-2 border-l-2 border-border-strong pl-3">
                    {sessionDelta.typeFrom !== null && (
                        <ChangeRow
                            label="type"
                            from={sessionDelta.typeFrom}
                            to={sessionDelta.typeTo}
                            direction="neutral"
                            tag="eased"
                        />
                    )}
                    {sessionDelta.distanceFrom !== null && (
                        <ChangeRow
                            className={
                                sessionDelta.typeFrom !== null
                                    ? 'mt-1.5'
                                    : undefined
                            }
                            label="km"
                            from={sessionDelta.distanceFrom}
                            to={sessionDelta.distanceTo}
                            direction={sessionDelta.direction}
                            tag="eased"
                        />
                    )}
                    {day.eased_from?.voice !== null && (
                        <p className="mt-1 text-sm leading-relaxed text-text-2">
                            {day.eased_from?.voice}
                        </p>
                    )}
                </div>
            )}
            {paceDelta && (
                <div className="mt-2 border-l-2 border-border-strong pl-3">
                    <ChangeRow
                        label="pace"
                        from={paceDelta.from}
                        to={paceDelta.to}
                        direction="neutral"
                        tag="eased"
                    />
                    {day.pace_eased_from?.voice !== null && (
                        <p className="mt-1 text-sm leading-relaxed text-text-2">
                            {day.pace_eased_from?.voice}
                        </p>
                    )}
                </div>
            )}
            {day.clamp !== null && (
                <div className="mt-2 border-l-2 border-border-strong pl-3">
                    <p className="flex items-center gap-1.5 text-label-micro text-text-2">
                        <Icon icon={ArrowDown} className="size-3" aria-hidden />
                        {day.clamp.label}
                    </p>
                    <p className="mt-0.5 text-sm font-semibold text-foreground">
                        {clampSummary(day.clamp, day.distance_km)}
                    </p>
                    <p className="mt-1 text-sm leading-relaxed text-text-2">
                        {day.clamp.note}
                    </p>
                </div>
            )}
        </div>
    );
}

/**
 * The prototype's today message card, carrying the whole of today: Temari as
 * a watermark posed to today's vibe, the "today" eyebrow, the session the plan
 * asks for with its pace and any readiness step-down, then Temari's read on it.
 */
export default function TodaySession({
    briefing,
    today = null,
    drawnAnchors = new Set<string>(),
}: Readonly<{
    briefing: BriefingResult;
    /** Today's row of `weekPlan.days`, null when no plan covers today. */
    today?: WeekPlanDay | null;
    /** From {@link drawnHomeAnchors} — which citations this page can honour. */
    drawnAnchors?: ReadonlySet<string>;
}>) {
    const voice = briefing.mascotVoice;
    const showsVoice =
        briefing.firstRead ||
        (voice.status !== 'pending' &&
            !(voice.status === 'done' && voice.content === null));

    return (
        <Card
            as="section"
            className="relative isolate overflow-hidden border-today-accent"
        >
            <MascotWatermark
                pose={writingPose(briefing.mood, voice)}
                className="-top-18 -right-14"
            />
            <Eyebrow token="micro" className="text-icon-accent">
                Today
            </Eyebrow>
            {today !== null && <TodayPrescription day={today} />}
            {showsVoice && (
                <div className="mt-3">
                    <AnalysisStatus
                        analysis={voice}
                        inertiaReloadProps={['briefing']}
                        allowReanalyze={false}
                        awaitingSchedule={briefing.firstRead}
                        awaitingScheduleLabel="temari is reading your first week…"
                        renderContent={(text) => (
                            <SessionVoice
                                text={text}
                                drawnAnchors={drawnAnchors}
                            />
                        )}
                    />
                </div>
            )}
        </Card>
    );
}
