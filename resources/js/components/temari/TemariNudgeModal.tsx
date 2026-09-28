import { Suspense, useState } from 'react';

import { lazyIsland } from '@/lib/lazyIsland';

import type { TemariNudgeModalProps } from './TemariNudgeDialog';

const TemariNudgeDialog = lazyIsland(() => import('./TemariNudgeDialog'));

/**
 * Temari's soft "front door" modal: a calm nudge (not a celebration) with a
 * title, a short body, and a primary + dismiss CTA. Backs
 * {@see DemoBlockedModal} and the destructive confirmations. The dialog and
 * Base UI under it load on the first open, so the pages that offer one keep
 * them off their first paint; once loaded it stays mounted to animate out.
 */
export default function TemariNudgeModal(
    props: Readonly<TemariNudgeModalProps>,
) {
    const [asked, setAsked] = useState(props.open);

    if (props.open && !asked) {
        setAsked(true);
    }

    return asked ? (
        <Suspense fallback={null}>
            <TemariNudgeDialog {...props} />
        </Suspense>
    ) : null;
}
