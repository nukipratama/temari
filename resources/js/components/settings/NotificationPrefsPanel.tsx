import { Bell, Sunrise } from 'lucide-react';
import { type ReactNode } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import PushNotificationToggle from '@/components/PushNotificationToggle';
import BriefingSendTime from '@/components/settings/BriefingSendTime';
import TelegramPanel, {
    type TelegramPayload,
} from '@/components/settings/TelegramPanel';
import TestSendButton from '@/components/settings/TestSendButton';
import SettingsRow from '@/components/ui/SettingsRow';
import Toggle from '@/components/ui/Switch';
import {
    useNotificationPrefs,
    type NotificationPrefs,
} from '@/pages/Settings/useNotificationPrefs';

export default function NotificationPrefsPanel({
    prefs,
    telegram,
    testCooldownSeconds,
}: Readonly<{
    prefs: NotificationPrefs;
    telegram: TelegramPayload;
    testCooldownSeconds: number | null;
}>) {
    const {
        notificationsEnabled,
        briefingPushEnabled,
        briefingPushMinute,
        telegramEnabled,
        pushEnabled,
        setNotificationsEnabled,
        setBriefingPushEnabled,
        setBriefingPushMinute,
        setTelegramEnabled,
        setPushEnabled,
        guard,
        demoModalOpen,
        setDemoModalOpen,
    } = useNotificationPrefs({ prefs });

    return (
        <div className="flex flex-col gap-6">
            <div>
                <GroupLabel>What gets sent</GroupLabel>
                {/* One switch, not three. Per-type toggles asked the user to
                    curate a list they never wanted to curate, and the streak
                    nudge had no toggle of its own at all, so it silently rode
                    along on "weekly recap". Naming everything here is what
                    makes that coupling honest. The briefing is the one
                    exception: it goes out on a clock, not after an event, so
                    its timing is the athlete's to tune. */}
                <div className="flex flex-col">
                    <SettingsRow
                        icon={Bell}
                        label="keep me posted"
                        description="post-run recaps, weekly and monthly summaries, your morning briefing, word when your race time gets quicker, a check on whether a hard run was your all-out trial, a warning when easy runs running hot will cost next week a quality session, word when the plan changes a session still ahead this week, a heads-up the day before a race and a check-in on how it went, plus a nudge when your streak's about to end."
                        control={
                            <Toggle
                                label="keep me posted"
                                checked={notificationsEnabled}
                                onChange={setNotificationsEnabled}
                            />
                        }
                    />
                    <SettingsRow
                        icon={Sunrise}
                        label="morning briefing"
                        description="an hour before your usual run"
                        control={
                            <Toggle
                                label="morning briefing"
                                checked={briefingPushEnabled}
                                onChange={setBriefingPushEnabled}
                                disabled={!notificationsEnabled}
                            />
                        }
                    />
                    <BriefingSendTime
                        minute={briefingPushMinute}
                        disabled={!notificationsEnabled || !briefingPushEnabled}
                        onChange={setBriefingPushMinute}
                    />
                </div>
            </div>

            <div className="border-t border-border/60 pt-5">
                <GroupLabel>Where it goes</GroupLabel>
                {/* Scoped on purpose: these switches govern the run notifications
                    above them, not everything the app can send. Maintainer alerts
                    (dead-lettered AI blocks, generation pauses) go straight to
                    admin Telegram chats without touching preferences, and the bot
                    still replies to /start and /stop. See MaintainerAlerter. */}
                <p className="mb-2 px-2 font-sans text-xs text-text-3">
                    controls your run notifications. Telegram and push each get
                    all of them, so to keep just one, mute the other. bot
                    replies and system alerts still come through.
                </p>
                <div className="flex flex-col">
                    <TelegramPanel
                        telegram={telegram}
                        muted={!telegramEnabled}
                        onMuteChange={(muted) => setTelegramEnabled(!muted)}
                    />
                    <PushNotificationToggle
                        muted={!pushEnabled}
                        onMuteChange={(muted) => setPushEnabled(!muted)}
                    />
                </div>
                {/* Lives with the channels rather than the types: what it proves
                    is that a channel can reach you, not that a type is on. */}
                <div className="mt-4">
                    <TestSendButton
                        cooldownSeconds={testCooldownSeconds}
                        guard={guard}
                    />
                </div>
            </div>

            <DemoBlockedModal
                open={demoModalOpen}
                onClose={() => setDemoModalOpen(false)}
            />
        </div>
    );
}

/** Sub-heading inside a settings card, one tier below the section Eyebrow. */
function GroupLabel({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <div className="mb-2 px-2 text-label-micro font-semibold text-text-3">
            {children}
        </div>
    );
}
