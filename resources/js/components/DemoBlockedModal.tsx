import { router } from '@inertiajs/react';

import TemariNudgeModal from '@/components/temari/TemariNudgeModal';

interface DemoBlockedModalProps {
    open: boolean;
    onClose: () => void;
}

/**
 * Friendly front door for a demo visitor hitting a blocked Telegram action.
 * The `block-demo-telegram` middleware is the real guard; this is the soft
 * upsell shown instead of a silent 403/redirect. Uses the shared
 * {@see TemariNudgeModal} shell (a calm nudge, not a win celebration).
 */
export default function DemoBlockedModal({
    open,
    onClose,
}: Readonly<DemoBlockedModalProps>) {
    return (
        <TemariNudgeModal
            open={open}
            onClose={onClose}
            title="Telegram's taking a break for now"
            body={
                <>
                    This is still the demo, so I&apos;ve switched off Telegram
                    here, that keeps this shared bot from getting tapped by
                    someone else. Connect your own Strava and you&apos;ll get
                    real notifications on your phone.
                </>
            }
            primaryLabel="Connect Strava"
            primaryIcon="mdi:strava"
            primaryClassName="bg-strava-orange text-white hover:bg-strava-orange-hover"
            onPrimary={() => router.post('/logout')}
        />
    );
}
