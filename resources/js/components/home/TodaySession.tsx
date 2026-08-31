import type { BriefingResult } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Temari from '@/components/temari/Temari';
import Card from '@/components/ui/LegacyCard';
import SectionLabel from '@/components/ui/SectionLabel';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
import { MOOD_TO_POSE } from '@/lib/temariPose';

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
        <div className="space-y-1.5">
            <p className="font-sans text-xl font-bold leading-tight tracking-[-0.02em] text-cream">
                {renderBold(stripEdgeQuotes(lead))}
            </p>
            {body !== '' && (
                <p className="font-sans text-sm leading-relaxed text-ink-on-sky">
                    {renderBold(body)}
                </p>
            )}
        </div>
    );
}

/**
 * Today's session: the one forward-looking block on a page that is otherwise
 * a backward-looking verdict. Sits directly under the evidence so the answer
 * to "am I getting better?" is read before anything asks for a run.
 */
export default function TodaySession({
    briefing,
}: Readonly<{ briefing: BriefingResult }>) {
    return (
        <Card as="section" tone="sky" padding="card">
            <div className="flex items-center gap-3">
                <Temari pose={MOOD_TO_POSE[briefing.mood]} size={42} />
                <SectionLabel dot dotClass="bg-horizon" onSky className="mb-0">
                    Today
                </SectionLabel>
            </div>

            <div className="mt-3">
                <AnalysisStatus
                    analysis={briefing.mascotVoice}
                    inertiaReloadProps={['briefing']}
                    allowReanalyze={false}
                    showTimestamp={false}
                    onSky
                    renderContent={(text) => <SessionVoice text={text} />}
                />
            </div>
        </Card>
    );
}
