import type { BriefingResult, WeekPlanDay } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { renderNarration } from '@/components/temari/Citation';
import FaceIcon from '@/components/temari/FaceIcon';
import NarrationFlag from '@/components/temari/NarrationFlag';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import {
    clampSummary,
    kmLabel,
    paceLabel,
    SESSION_TYPE_LABEL,
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
 * distance and the pace it is run at, then the readiness step-down when one
 * applies. The step-down is a marked modification of the prescription rather
 * than a replacement of it, so both figures stand — see
 * `docs/decisions/readiness-clamp-is-advisory.md`.
 */
function TodayPrescription({ day }: Readonly<{ day: WeekPlanDay }>) {
    const pace = paceLabel(day);
    const parts = [SESSION_TYPE_LABEL[day.session_type] ?? day.session_type];
    if (day.session_type !== 'rest') {
        parts.push(kmLabel(day));
        if (pace !== null) {
            parts.push(pace);
        }
    }

    return (
        <div
            id="anchor-session-today"
            className="mb-3 rounded-lg bg-muted px-3 py-2.5"
        >
            <p className="text-sm font-semibold text-foreground">
                {parts.join(' · ')}
            </p>
            {day.clamp !== null && (
                <div className="mt-2 border-l-2 border-border-strong pl-3">
                    <p className="flex items-center gap-1.5 text-label-micro text-text-2">
                        <Icon
                            icon="mdi:arrow-down"
                            className="size-3"
                            aria-hidden
                        />
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
 * The prototype's today message card, carrying the whole of today: a
 * leaf-ringed `FaceIcon` beside the "today" eyebrow, the session the plan asks
 * for with its pace and any readiness step-down, then Temari's read on it.
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
    return (
        <Card as="section" className="border-today-accent">
            <div className="flex items-start gap-3">
                <FaceIcon size={42} ring="var(--color-leaf)" />
                <div className="min-w-0 flex-1">
                    <div className="mb-1 flex items-center justify-between gap-2">
                        <Eyebrow token="micro" className="text-icon-accent">
                            Today
                        </Eyebrow>
                        <NarrationFlag analysis={briefing.mascotVoice} />
                    </div>
                    {today !== null && <TodayPrescription day={today} />}
                    <AnalysisStatus
                        analysis={briefing.mascotVoice}
                        inertiaReloadProps={['briefing']}
                        allowReanalyze={false}
                        showTimestamp={false}
                        renderContent={(text) => (
                            <SessionVoice
                                text={text}
                                drawnAnchors={drawnAnchors}
                            />
                        )}
                    />
                </div>
            </div>
        </Card>
    );
}
