import type { ReactNode } from 'react';

import { router } from '@inertiajs/react';

import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { StravaIcon } from '@/components/ui/Icon';

interface DemoBlockedModalProps {
    open: boolean;
    onClose: () => void;
    title?: string;
    body?: ReactNode;
}

/**
 * Friendly front door for a demo visitor hitting a blocked write. The
 * `block-demo-telegram` middleware is the real guard; this is the soft
 * upsell shown instead of a silent 403/redirect. The copy defaults to the
 * Telegram wording. Uses the shared {@see TemariNudgeModal} shell (a calm
 * nudge, not a win celebration).
 */
export default function DemoBlockedModal({
    open,
    onClose,
    title = "Telegram's taking a break for now",
    body = (
        <>
            this is still the demo, so i&apos;ve switched off Telegram here,
            that keeps this shared bot from getting tapped by someone else.
            connect your own Strava and you&apos;ll get real notifications on
            your phone.
        </>
    ),
}: Readonly<DemoBlockedModalProps>) {
    return (
        <TemariNudgeModal
            open={open}
            onClose={onClose}
            title={title}
            body={body}
            primaryLabel="connect Strava"
            primaryIcon={StravaIcon}
            primaryTone="outline"
            onPrimary={() => router.post('/logout')}
        />
    );
}
