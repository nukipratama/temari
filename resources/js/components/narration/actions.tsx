import { useForm } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';

import { Icon } from '@/components/ui/Icon';
import { athletePath, OVERVIEW_PATH } from '@/pages/Narration/helpers';

/**
 * One-shot "resume everything": re-arms every dead-lettered block across
 * athletes and runs the self-heal sweep immediately, instead of an N-click,
 * up-to-60-min-cadence scavenger hunt. Shown on whichever fault tile it
 * resolves (a pause, an app-wide ceiling trip).
 */
export function RecoverAllButton() {
    const { post, processing } = useForm();

    return (
        <button
            type="button"
            onClick={() =>
                post(`${OVERVIEW_PATH}/recover`, { preserveScroll: true })
            }
            disabled={processing}
            className="focus-ring inline-flex shrink-0 items-center gap-1.5 rounded-full bg-sky px-4 py-2 text-xs font-semibold text-cream transition-colors hover:bg-sky-deep disabled:cursor-wait disabled:opacity-60"
        >
            <Icon icon={RotateCcw} aria-hidden />
            <span>recover all</span>
        </button>
    );
}

/**
 * Re-arms and re-dispatches this athlete's failed blocks. Rendered only where
 * something is dead-lettered, so the button never promises work it has none of.
 */
export function RetryFailedButton({ userId }: Readonly<{ userId: number }>) {
    const { post, processing } = useForm();

    return (
        <button
            type="button"
            onClick={() =>
                post(`${athletePath(userId)}/retry-failed`, {
                    preserveScroll: true,
                })
            }
            disabled={processing}
            className="focus-ring inline-flex shrink-0 items-center rounded-full bg-sky px-3 py-1.5 text-xs font-semibold text-cream transition-colors hover:bg-sky-deep disabled:cursor-wait disabled:opacity-60"
        >
            retry failed
        </button>
    );
}
