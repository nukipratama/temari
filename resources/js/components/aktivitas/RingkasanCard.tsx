import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { cn } from '@/lib/cn';
import { renderBold } from '@/lib/richText';
import type { AnalysisPayload } from '@/types/inertia';

interface RingkasanCardProps {
    analysis: AnalysisPayload;
    /** Used to refresh just the relevant props after a retry, matching the parent page. */
    inertiaReloadProps?: string[];
    /** When the LLM hasn't produced a recap yet, this fallback prose stays visible. */
    fallback: string;
    className?: string;
}

const DEFAULT_RELOAD_PROPS = ['weeklySnapshots', 'historicalSnapshots'];

/**
 * Renders Temari's weekly recap narrative. While the LLM job is pending /
 * processing / failed, shows the rule-based `fallback` so the section never
 * looks empty. Reuses the central {@link AnalysisStatus} state machine for
 * spinner, retry button, error chip.
 */
export default function RingkasanCard({
    analysis,
    inertiaReloadProps = DEFAULT_RELOAD_PROPS,
    fallback,
    className,
}: Readonly<RingkasanCardProps>) {
    return (
        <section
            className={cn(
                'rounded-2xl border border-line bg-surface-warm p-4 shadow-sm sm:p-5',
                className,
            )}
            aria-label="Catatan Temari minggu ini"
        >
            <div className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
                Catatan Temari
            </div>
            <div className="mt-2">
                <AnalysisStatus
                    analysis={analysis}
                    inertiaReloadProps={inertiaReloadProps}
                    size="md"
                    renderContent={(content) => (
                        <p className="text-sm leading-relaxed text-ink">{renderBold(content)}</p>
                    )}
                />
            </div>
            {analysis.status !== 'done' && (
                <p className="mt-2 text-sm leading-relaxed text-ink-2">{fallback}</p>
            )}
        </section>
    );
}
