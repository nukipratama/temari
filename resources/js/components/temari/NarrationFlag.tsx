import type { AnalysisPayload } from '@/types/inertia';

import FlagWrong from './FlagWrong';

/**
 * The flag for one narration block, drawn by the host in its own header row so
 * it sits top-right level with the eyebrow rather than alone under the text.
 * A block with nothing said yet, or no row behind it, has nothing to flag.
 */
export default function NarrationFlag({
    analysis,
    onSky = false,
}: Readonly<{
    analysis: AnalysisPayload;
    /** Cream-on-sky styling, for a block drawn on a dark panel. */
    onSky?: boolean;
}>) {
    if (
        analysis.status !== 'done' ||
        analysis.content === null ||
        analysis.id === null
    ) {
        return null;
    }

    return (
        <FlagWrong
            subjectType="narration"
            subjectId={analysis.id}
            label="flag this read"
            flagged={analysis.flagged === true}
            onSky={onSky}
            compact
        />
    );
}
