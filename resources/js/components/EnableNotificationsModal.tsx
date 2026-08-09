import { router } from '@inertiajs/react';

import TemariNudgeModal from '@/components/temari/TemariNudgeModal';

interface EnableNotificationsModalProps {
    open: boolean;
    onClose: () => void;
}

/**
 * Soft front door behind the muted "Send notification" pill for a user who has no
 * channel wired yet. Channel-neutral on purpose: push notifications and Telegram both
 * live on Settings, so this points there instead of pushing one channel. Uses
 * the shared {@see TemariNudgeModal} shell.
 */
export default function EnableNotificationsModal({
    open,
    onClose,
}: Readonly<EnableNotificationsModalProps>) {
    return (
        <TemariNudgeModal
            open={open}
            onClose={onClose}
            title="Turn on notifications first"
            body={
                <>
                    Your notifications aren&apos;t on yet. Once they are,
                    I&apos;ll let you know right after every run and at recap
                    time, so nothing slips by. Push notifications or Telegram,
                    your pick.
                </>
            }
            primaryLabel="Go to Settings"
            primaryIcon="mdi:bell-outline"
            onPrimary={() => router.visit('/settings')}
        />
    );
}
