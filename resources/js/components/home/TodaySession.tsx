import type { BriefingResult } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import FaceIcon from '@/components/temari/FaceIcon';
import Eyebrow from '@/components/ui/Eyebrow';
import Card from '@/components/ui/LegacyCard';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';

/**
 * Temari's read on today, split into the line that leads and the rest. The
 * narrators are prompted to open with a standalone first paragraph; anything
 * that ignores that renders as a single lead line.
 */
function SessionVoice({ text }: Readonly<{ text: string }>) {
    const parts = text
        .split(/\n\n+/)
        .map((part) => part.trim())
        .filter(Boolean);

    if (parts.length === 0) {
        return null;
    }

    const [lead, ...rest] = parts;
    const body = rest.join(' ');

    return (
        <>
            <p className="font-serif text-[0.84375rem] font-bold italic leading-relaxed text-foreground">
                {renderBold(stripEdgeQuotes(lead))}
            </p>
            {body !== '' && (
                <p className="mt-2.5 font-serif text-[0.78125rem] italic leading-relaxed text-foreground">
                    {renderBold(body)}
                </p>
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
