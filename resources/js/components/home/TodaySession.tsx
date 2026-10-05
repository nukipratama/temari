import { useRef } from 'react';

import type {
    BriefingResult,
    RestDayEasePace,
    WeekPlanDay,
} from '@/types/inertia';

import { AskedRanResult, ChangeRow } from '@/components/plan/DeltaPair';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { renderNarration } from '@/components/temari/Citation';
import MascotWatermark from '@/components/temari/MascotWatermark';
import { type MascotPose, writingPose } from '@/components/temari/TemariMascot';
import Eyebrow from '@/components/ui/Eyebrow';
import { useRecommendationView } from '@/hooks/useRecommendationView';
import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';
import {
    easedFromDelta,
    judgedDayResult,
    paceEaseDelta,
    paceLabel,
    prescriptionWhy,
    sessionLabel,
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
            <p className="font-serif text-headline-sm text-foreground italic">
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
function TodayPrescription({
    day,
    restDayEasePace,
}: Readonly<{ day: WeekPlanDay; restDayEasePace: RestDayEasePace | null }>) {
    const judged = judgedDayResult(day);
    const pace = judged === null ? paceLabel(day) : null;
    const sessionDelta = day.eased_from
        ? easedFromDelta(day.eased_from, day)
        : null;
    const paceDelta = day.pace_eased_from
        ? paceEaseDelta(day.pace_eased_from, day)
        : null;
    const heroKm = day.session_type !== 'rest' && judged === null;
    const ranUnjudged = heroKm && day.actual_km !== null;
    const parts = [sessionLabel(day)];
    if (heroKm && pace !== null) {
        parts.push(pace);
    }
    const shape = judged === null ? sessionShape(day.segments) : null;
    const purpose = judged === null ? sessionPurpose(day) : null;
    const doseWhy = judged === null ? prescriptionWhy(day) : null;

    return (
        <div id="anchor-session-today" className="mt-2">
            {heroKm && (
                <p className="flex flex-wrap items-baseline gap-x-1.5">
                    <span className="text-stat">
                        {ranUnjudged ? day.actual_km : day.distance_km}
                    </span>
                    <span className="text-meta">
                        {ranUnjudged ? `/ ${day.distance_km} km planned` : 'km'}
                    </span>
                </p>
            )}
            <p
                className={cn(
                    'text-sm font-semibold text-foreground',
                    heroKm && 'mt-1',
                )}
            >
                {parts.join(' · ')}
            </p>
            {day.session_type === 'rest' && restDayEasePace !== null && (
                <p className="mt-1 text-xs leading-relaxed text-text-2">
                    your easy runs after a rest day go{' '}
                    <span className="font-mono text-foreground">
                        {`${formatPace(restDayEasePace.deltaSecPerKm)}/km`}
                    </span>{' '}
                    {restDayEasePace.direction}
                </p>
            )}
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
            {day.result_note && (
                <p className="mt-1.5 text-xs leading-relaxed text-text-2">
                    {day.result_note}
                </p>
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
            {day.advice_note !== null && (
                <p className="mt-2 border-l-2 border-border-strong pl-3 text-sm leading-relaxed text-text-2">
                    {day.advice_note}
                </p>
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
    restDayEasePace = null,
    drawnAnchors = new Set<string>(),
}: Readonly<{
    briefing: BriefingResult;
    /** Today's row of `weekPlan.days`, null when no plan covers today. */
    today?: WeekPlanDay | null;
    /** Deferred; only ever set alongside a rest-day `today`. */
    restDayEasePace?: RestDayEasePace | null;
    /** From {@link drawnHomeAnchors} — which citations this page can honour. */
    drawnAnchors?: ReadonlySet<string>;
}>) {
    const voice = briefing.mascotVoice;
    const recommendationRef = useRef<HTMLElement>(null);
    useRecommendationView(recommendationRef, today?.recommendation_token);
    const showsVoice =
        briefing.firstRead ||
        (voice.status !== 'pending' &&
            !(voice.status === 'done' && voice.content === null));
    const pose: MascotPose =
        today?.session_type === 'rest' ? 'sleepy' : briefing.mood;

    return (
        <section
            ref={recommendationRef}
            className="relative isolate overflow-hidden"
        >
            <MascotWatermark
                pose={writingPose(pose, voice)}
                className="-top-18 -right-14"
            />
            <Eyebrow token="micro" className="text-icon-accent">
                Today
            </Eyebrow>
            {today !== null && (
                <TodayPrescription
                    day={today}
                    restDayEasePace={restDayEasePace}
                />
            )}
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
        </section>
    );
}
