import type { BriefingResult } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import FaceIcon from '@/components/temari/FaceIcon';
import Eyebrow from '@/components/ui/Eyebrow';
import Card from '@/components/ui/LegacyCard';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';

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
function SessionVoice({ text }: Readonly<{ text: string }>) {
    const [lead, body] = leadAndBody(text);

    if (lead === '') {
        return null;
    }

    return (
        <>
            <p className="narration font-semibold">
                {renderBold(stripEdgeQuotes(lead))}
            </p>
            {body !== '' && (
                <p className="narration-dense mt-2.5">{renderBold(body)}</p>
            )}
        </>
    );
}

/**
 * The prototype's today message card: a leaf-ringed `FaceIcon` beside the
 * "today" eyebrow and the line that leads, on a `today-accent` edge rather
 * than the app's dark sky panel.
 */
export default function TodaySession({
    briefing,
}: Readonly<{ briefing: BriefingResult }>) {
    return (
        <Card as="section" className="border-today-accent">
            <div className="flex items-start gap-3">
                <FaceIcon size={42} ring="var(--color-leaf)" />
                <div className="min-w-0 flex-1">
                    <Eyebrow token="micro" className="mb-1 text-icon-accent">
                        Today
                    </Eyebrow>
                    <AnalysisStatus
                        analysis={briefing.mascotVoice}
                        inertiaReloadProps={['briefing']}
                        allowReanalyze={false}
                        showTimestamp={false}
                        renderContent={(text) => <SessionVoice text={text} />}
                    />
                </div>
            </div>
        </Card>
    );
}
