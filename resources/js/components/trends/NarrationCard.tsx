import type { AnalysisPayload } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';

interface NarrationCardProps {
    analysis: AnalysisPayload;
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
    className,
}: Readonly<NarrationCardProps>) {
    return (
        <Card as="section" tone="narration" className={className}>
            <Eyebrow
                token="micro"
                className="mb-1.5 flex items-center gap-1.5 text-icon-accent"
            >
                <Icon icon="mdi:auto-awesome" className="size-3" aria-hidden />
                Temari&apos;s read
            </Eyebrow>
            <AnalysisStatus
                analysis={analysis}
                inertiaReloadProps={['narration']}
                awaitingSchedule={false}
                showTimestamp={false}
                renderContent={(content) => {
                    const { title, description } = splitContent(content);
                    return (
                        <>
                            <p className="font-serif text-[0.9375rem] font-bold italic leading-tight text-foreground">
                                {title}
                            </p>
                            {description !== '' && (
                                <p className="mt-1.5 font-serif text-[0.78125rem] italic leading-relaxed text-foreground">
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
