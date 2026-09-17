import { Sparkles } from 'lucide-react';

import type { AnalysisPayload } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';

interface NarrationCardProps {
    analysis: AnalysisPayload;
    /** Shown under the headline when the read's own window differs from
     *  whatever range is currently selected (e.g. the 7-day toggle position,
     *  which borrows the 30-day read rather than going blank). */
    note?: string;
    className?: string;
}

/**
 * Splits the narrator's "{title}\n\n{description}" shape (see
 * TrendReadNarrator::generate()) into a bold headline and a supporting
 * paragraph. Falls back to rendering the whole string as the title when a
 * rule-based fallback or an older row doesn't carry the blank-line split.
 */
function splitContent(content: string): { title: string; description: string } {
    const [title, ...rest] = content.split('\n\n');
    return { title: title.trim(), description: rest.join('\n\n').trim() };
}

/** The prototype's "temari's read" card: a haloed voice card holding a bold
 *  italic lead and the paragraph behind it. */
export default function NarrationCard({
    analysis,
    note,
    className,
}: Readonly<NarrationCardProps>) {
    return (
        <Card as="section" tone="narration" className={className}>
            <Eyebrow
                token="micro"
                className="mb-1.5 flex items-center gap-1.5 text-icon-accent"
            >
                <Icon icon={Sparkles} className="size-3" aria-hidden />
                Temari&apos;s read
            </Eyebrow>
            {note !== undefined && (
                <p className="mb-1.5 text-xs text-text-2">{note}</p>
            )}
            <AnalysisStatus
                analysis={analysis}
                inertiaReloadProps={['narration']}
                awaitingSchedule={false}
                renderContent={(content) => {
                    const { title, description } = splitContent(content);
                    return (
                        <>
                            <p className="narration font-semibold">{title}</p>
                            {description !== '' && (
                                <p className="narration-dense mt-1.5">
                                    {description}
                                </p>
                            )}
                        </>
                    );
                }}
            />
        </Card>
    );
}

export { splitContent };
