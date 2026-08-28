import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

import type { AnalysisPayload } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { cn } from '@/lib/cn';

interface NarrationHeadlineProps {
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

/**
 * Reuses CardReveal's "ignition ring" pattern (see resources/js/components/
 * card/CardReveal.tsx) as this page's signature delight moment — a glow that
 * plays once on mount around the one narrated read everything else on the
 * page is evidence for.
 */
export default function NarrationHeadline({
    analysis,
    className,
}: Readonly<NarrationHeadlineProps>) {
    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-(--radius-panel) border border-horizon-ink/25 bg-horizon/10 p-6 sm:p-8',
                className,
            )}
        >
            <motion.span
                aria-hidden
                initial={{ opacity: 0.85, scale: 0.96 }}
                animate={{ opacity: 0, scale: 1.18 }}
                transition={{ duration: 0.7, ease: 'easeOut' }}
                className="pointer-events-none absolute inset-0 rounded-(--radius-panel)"
                style={{
                    boxShadow:
                        '0 0 0 3px var(--color-horizon-ink), 0 0 24px 6px var(--color-horizon-ink)',
                }}
            />
            <div className="flex items-center gap-1.5 text-horizon-ink">
                <Icon
                    icon="mdi:auto-awesome"
                    className="size-3.5"
                    aria-hidden
                />
                <span className="text-label-micro">Temari&apos;s read</span>
            </div>
            <div className="mt-3">
                <AnalysisStatus
                    analysis={analysis}
                    inertiaReloadProps={['narration']}
                    awaitingSchedule={false}
                    renderContent={(content) => {
                        const { title, description } = splitContent(content);
                        return (
                            <>
                                <p className="font-serif text-xl leading-snug text-foreground sm:text-2xl">
                                    {title}
                                </p>
                                {description !== '' && (
                                    <p className="mt-2 max-w-full text-sm leading-relaxed text-text-2 sm:text-base">
                                        {description}
                                    </p>
                                )}
                            </>
                        );
                    }}
                />
            </div>
        </div>
    );
}

export { splitContent };
