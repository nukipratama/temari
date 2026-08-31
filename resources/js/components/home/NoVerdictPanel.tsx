import type { PastYouTrend } from '@/types/inertia';

import EvidenceList from '@/components/home/EvidenceList';
import EmptyPanel from '@/components/ui/EmptyPanel';
import SectionLabel from '@/components/ui/SectionLabel';
import { verdictHeadline, verdictSupport } from '@/lib/verdict';

/**
 * `not_enough_history`, the Past You empty state. Not an error and not a
 * failure to load: the window held fewer than two comparable pairs, so there
 * is nothing to call either way.
 *
 * One comparable pair reads differently from none. A single pair is shown as
 * evidence anyway, because "one match, not a trend yet" is a more useful thing
 * to be told than "nothing yet" when the runner is one run away from a verdict.
 */
export default function NoVerdictPanel({
    trend,
}: Readonly<{ trend: PastYouTrend }>) {
    const hasNearMiss = trend.comparison_count > 0;

    return (
        <section>
            <SectionLabel dot dotClass="bg-horizon">
                You vs Past You · Last {trend.window_days} Days
            </SectionLabel>

            <EmptyPanel
                className="mt-0"
                face
                title={verdictHeadline(trend)}
                body={verdictSupport(trend)}
            />

            {hasNearMiss && (
                <div className="mt-4">
                    <EvidenceList trend={trend} />
                </div>
            )}
        </section>
    );
}
