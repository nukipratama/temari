import { router } from '@inertiajs/react';
import { Unlink } from 'lucide-react';
import { useState } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { TelegramIcon } from '@/components/ui/Icon';
import SettingsDisconnectLink from '@/components/ui/SettingsDisconnectLink';
import SettingsRow from '@/components/ui/SettingsRow';
import Toggle from '@/components/ui/Switch';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { showToast } from '@/hooks/useToast';

export interface TelegramPayload {
    connected: boolean;
    username: string | null;
    connect_url: string | null;
}

export default function TelegramPanel({
    telegram,
    muted,
    onMuteChange,
}: Readonly<{
    telegram: TelegramPayload;
    muted: boolean;
    onMuteChange: (muted: boolean) => void;
}>) {
    const { isDemo, open, setOpen, guard } = useDemoGuard();
    const [confirmingDisconnect, setConfirmingDisconnect] = useState(false);

    if (!telegram.connected) {
        // Whole-row tap when the row means one thing ("go connect"); a discrete
        // control only once there is an action distinct from the row itself.
        if (isDemo) {
            return (
                <SettingsRow
                    icon={TelegramIcon}
                    label="Telegram"
                    description="connect it so temari can keep you posted."
                    onClick={() => setOpen(true)}
                >
                    <DemoBlockedModal
                        open={open}
                        onClose={() => setOpen(false)}
                    />
                </SettingsRow>
            );
        }

        if (telegram.connect_url === null) {
            return (
                <SettingsRow
                    icon={TelegramIcon}
                    label="Telegram"
                    description="the Telegram bot isn't configured yet."
                    control={<span aria-hidden />}
                />
            );
        }

        return (
            <SettingsRow
                icon={TelegramIcon}
                label="Telegram"
                description="connect it so temari can keep you posted."
                externalHref={telegram.connect_url}
            />
        );
    }

    // Mute sits beside the connection it silences, and only exists once there is
    // a connection — a mute on an unwired channel would mean nothing.
    let description = telegram.username
        ? `active · @${telegram.username}`
        : 'active';
    if (muted) {
        description = telegram.username
            ? `muted · @${telegram.username}`
            : 'muted';
    }

    return (
        <>
            <SettingsRow
                icon={TelegramIcon}
                label="Telegram"
                description={description}
                control={
                    <Toggle
                        label="send run notifications to Telegram"
                        checked={!muted}
                        onChange={(on) => onMuteChange(!on)}
                    />
                }
            />
            <div className="-mt-1 pl-11">
                <SettingsDisconnectLink
                    onClick={() => guard(() => setConfirmingDisconnect(true))}
                />
            </div>
            <TemariNudgeModal
                open={confirmingDisconnect}
                onClose={() => setConfirmingDisconnect(false)}
                pose="concerned"
                title="disconnect Telegram?"
                body="nothing goes to Telegram after this. you can connect it again from here."
                primaryLabel="disconnect"
                primaryIcon={Unlink}
                primaryTone="danger"
                onPrimary={() => {
                    setConfirmingDisconnect(false);
                    router.delete('/profile/telegram', {
                        preserveScroll: true,
                        onSuccess: () => showToast('Telegram disconnected'),
                    });
                }}
            />
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}
