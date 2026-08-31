import { Head, router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import PushNotificationToggle from '@/components/PushNotificationToggle';
import AppearanceCard from '@/components/settings/AppearanceCard';
import HrZonesDisclosure, {
    type HrZonesPayload,
} from '@/components/settings/HrZonesDisclosure';
import TrainingPreferencesDisclosure, {
    type TrainingPreferencesPayload,
} from '@/components/settings/TrainingPreferencesDisclosure';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import SettingsRow from '@/components/ui/SettingsRow';
import Toggle from '@/components/ui/Switch';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { usePendingPost } from '@/hooks/usePendingPost';
import { appLayout } from '@/layouts/appLayout';
import { formatDurationHMS } from '@/lib/pace';

import {
    useNotificationPrefs,
    type NotificationPrefs,
} from './useNotificationPrefs';

// The demo account can't be deleted; the backend guard rejects it and the
// shared ErrorBanner surfaces the reason, so the confirm modal stays generic.

interface TelegramPayload {
    connected: boolean;
    username: string | null;
    connect_url: string | null;
}

interface DataUsePayload {
    headline: string;
    points: string[];
}

interface SettingsProps {
    dataUse?: DataUsePayload;
    telegram?: TelegramPayload;
    notificationPrefs?: NotificationPrefs;
    /** Seconds left on the test-send cooldown, or null when it is not cooling. */
    testCooldownSeconds?: number | null;
    hrZones?: HrZonesPayload;
    trainingPreferences?: TrainingPreferencesPayload;
}

const LEGAL_ROWS: ReadonlyArray<{
    href: string;
    icon: string;
    label: string;
    description: string;
}> = [
    {
        href: '/terms',
        icon: 'mdi:file-document-outline',
        label: 'Terms of use',
        description: 'What Temari is, what it costs, and what it promises.',
    },
    {
        href: '/privacy',
        icon: 'mdi:lock-outline',
        label: 'Privacy policy',
        description: 'What is stored, who else sees it, what deletion removes.',
    },
    {
        href: '/ai-use',
        icon: 'mdi:robot-outline',
        label: 'How Temari uses AI',
        description: 'What is sent to the model, and what it never does.',
    },
    {
        href: '/training-disclaimer',
        icon: 'mdi:heart-pulse',
        label: 'Training disclaimer',
        description: 'Why the plan is specific, and when to ignore it.',
    },
];

const TELEGRAM_DEFAULT: TelegramPayload = {
    connected: false,
    username: null,
    connect_url: null,
};

const PREFS_DEFAULT: NotificationPrefs = {
    notifications_enabled: true,
    telegram_enabled: true,
    push_enabled: true,
};

// Mirrors config('runner.php')'s single-user defaults, the same fallback
// SettingsController hands back for a user with no RunnerProfile row.
const HR_ZONES_DEFAULT: HrZonesPayload = {
    profile: {
        max_hr: 180,
        resting_hr: 55,
        hr_zones: {
            Z1: { lo: 116, hi: 138 },
            Z2: { lo: 138, hi: 154 },
            Z3: { lo: 154, hi: 168 },
            Z4: { lo: 168, hi: 176 },
            Z5: { lo: 176, hi: 999 },
        },
        optimal_cadence_spm: 170,
    },
    source: 'default',
    stravaSyncedLabel: null,
    canSyncFromStrava: false,
};

const TRAINING_PREFERENCES_DEFAULT: TrainingPreferencesPayload = {
    experience_level: null,
    sessions_per_week: null,
    goal_type: null,
    run_days: null,
    long_run_day: null,
};

export default function Settings({
    dataUse,
    telegram = TELEGRAM_DEFAULT,
    notificationPrefs = PREFS_DEFAULT,
    testCooldownSeconds = null,
    hrZones = HR_ZONES_DEFAULT,
    trainingPreferences = TRAINING_PREFERENCES_DEFAULT,
}: Readonly<SettingsProps>) {
    return (
        <>
            <Head title="Settings" />
            <PageContainer>
                <header className="mb-8 flex flex-col gap-5">
                    <PageHero eyebrow="Settings" size="quote-lg" italic>
                        set up temari,{' '}
                        <em className="italic text-horizon-ink">your way.</em>
                    </PageHero>
                </header>

                {/* Leads the page: the appearance toggle is this slice's
                    headline feature, and nothing else lets a user actually
                    switch grounds by hand until it ships. Matches the
                    prototype's own section order. */}
                <section>
                    <SectionLabel>Appearance</SectionLabel>
                    <div className="mt-3">
                        <AppearanceCard />
                    </div>
                </section>

                {/* One notification section, not three. The user holds a single
                    model with two questions — what gets sent, and where it goes —
                    and splitting those across "Notifications", "Push" and
                    "Telegram" made them look unrelated. */}
                <section
                    className="mt-10"
                    data-coachmark="settings-notifications"
                >
                    <SectionLabel>Notifications</SectionLabel>
                    <div className="mt-3">
                        <Card className="px-6 py-6">
                            <NotificationPrefsPanel
                                prefs={notificationPrefs}
                                telegram={telegram}
                                testCooldownSeconds={testCooldownSeconds}
                            />
                        </Card>
                    </div>
                </section>

                <section className="mt-10" data-coachmark="settings-hr-zones">
                    <SectionLabel>Running</SectionLabel>
                    <div className="mt-3 flex flex-col gap-3">
                        <HrZonesDisclosure hrZones={hrZones} />
                        <TrainingPreferencesDisclosure
                            trainingPreferences={trainingPreferences}
                        />
                    </div>
                </section>

                {dataUse ? (
                    <section className="mt-10">
                        <SectionLabel>{dataUse.headline}</SectionLabel>
                        <div className="mt-3">
                            <Card className="px-6 py-6">
                                <ul className="flex flex-col gap-4">
                                    {dataUse.points.map((point) => (
                                        <li
                                            key={point}
                                            className="text-sm leading-relaxed text-text-2"
                                        >
                                            {point}
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        </div>
                    </section>
                ) : null}

                <section className="mt-10">
                    <SectionLabel>The fine print</SectionLabel>
                    <div className="mt-3">
                        <Card className="px-6 py-6">
                            {LEGAL_ROWS.map((row) => (
                                <SettingsRow
                                    key={row.href}
                                    icon={row.icon}
                                    label={row.label}
                                    description={row.description}
                                    href={row.href}
                                />
                            ))}
                        </Card>
                    </div>
                </section>

                <section className="mt-10">
                    <SectionLabel>Account</SectionLabel>
                    <div className="mt-3">
                        <Card className="px-6 py-6">
                            <SettingsRow
                                icon="mdi:logout"
                                label="Log out"
                                description="You can sign back in any time."
                                onClick={() => router.post('/logout')}
                            />
                            <DeleteAccountPanel />
                        </Card>
                    </div>
                </section>
            </PageContainer>
        </>
    );
}

function DeleteAccountPanel() {
    const [confirmOpen, setConfirmOpen] = useState(false);

    return (
        <>
            <SettingsRow
                icon="mdi:account-remove-outline"
                label="Delete account"
                description="Deletes your account and disconnects Strava. Can't be undone."
                tone="danger"
                onClick={() => setConfirmOpen(true)}
            />
            <TemariNudgeModal
                open={confirmOpen}
                onClose={() => setConfirmOpen(false)}
                title="Sure you want to delete your account?"
                body={
                    <>
                        All your runs, cards, and Strava connection will be
                        removed and can't be undone. If you just want to switch
                        Strava accounts, this is also how.
                    </>
                }
                primaryLabel="Yes, delete my account"
                primaryIcon="mdi:account-remove-outline"
                primaryClassName="bg-ember-deep text-cream hover:opacity-90"
                onPrimary={() => router.delete('/account')}
            />
        </>
    );
}

function NotificationPrefsPanel({
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
        telegramEnabled,
        pushEnabled,
        setNotificationsEnabled,
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
                    makes that coupling honest. */}
                <div className="flex flex-col">
                    <SettingsRow
                        icon="mdi:bell-outline"
                        label="Keep me posted"
                        description="Post-run recaps, weekly and monthly summaries, plus a nudge when your streak's about to end."
                        control={
                            <Toggle
                                label="Keep me posted"
                                checked={notificationsEnabled}
                                onChange={setNotificationsEnabled}
                            />
                        }
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
                <p className="mb-2 px-2 font-sans text-[12px] text-text-3">
                    Controls your run notifications. Bot replies and system
                    alerts still come through.
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

/**
 * "Send test notification" with the two states it was missing: in-flight, and
 * cooling. Without them a tap looked like nothing happened, and a second tap
 * either sent again or hit the route throttle as a bare 429.
 */
function TestSendButton({
    cooldownSeconds,
    guard,
}: Readonly<{
    cooldownSeconds: number | null;
    guard: (run: () => void) => void;
}>) {
    const [sending, send] = usePendingPost('/profile/notifications/test', {
        preserveScroll: true,
    });
    const remaining = useCooldownCountdown(cooldownSeconds);
    const cooling = remaining > 0;

    let label = 'Send test notification';
    if (cooling) {
        label = formatDurationHMS(remaining);
    } else if (sending) {
        label = 'Sending…';
    }

    return (
        <PillButton
            tone="outline"
            disabled={sending || cooling}
            className="disabled:cursor-not-allowed disabled:opacity-60"
            onClick={() => guard(send)}
            aria-label={cooldownAriaLabel(
                remaining,
                'sending a test notification',
            )}
        >
            <Icon
                icon={sending ? 'mdi:loading' : 'mdi:send-outline'}
                width={14}
                height={14}
                className={sending ? 'animate-spin' : undefined}
                aria-hidden
            />
            {label}
        </PillButton>
    );
}

/** Sub-heading inside a settings card, one tier below SectionLabel. */
function GroupLabel({ children }: Readonly<{ children: ReactNode }>) {
    return (
        <div className="mb-2 px-2 text-label-micro font-semibold text-text-3">
            {children}
        </div>
    );
}

function TelegramPanel({
    telegram,
    muted,
    onMuteChange,
}: Readonly<{
    telegram: TelegramPayload;
    muted: boolean;
    onMuteChange: (muted: boolean) => void;
}>) {
    const { isDemo, open, setOpen, guard } = useDemoGuard();

    if (!telegram.connected) {
        if (telegram.connect_url === null) {
            return (
                <SettingsRow
                    icon="mdi:telegram"
                    label="Telegram"
                    description="The Telegram bot isn't configured yet."
                    control={<span aria-hidden />}
                />
            );
        }

        // Whole-row tap when the row means one thing ("go connect"); a discrete
        // control only once there is an action distinct from the row itself.
        if (isDemo) {
            return (
                <SettingsRow
                    icon="mdi:telegram"
                    label="Telegram"
                    description="Connect it so Temari can keep you posted."
                    onClick={() => setOpen(true)}
                >
                    <DemoBlockedModal
                        open={open}
                        onClose={() => setOpen(false)}
                    />
                </SettingsRow>
            );
        }

        return (
            <SettingsRow
                icon="mdi:telegram"
                label="Telegram"
                description="Connect it so Temari can keep you posted."
                externalHref={telegram.connect_url}
            />
        );
    }

    // Mute sits beside the connection it silences, and only exists once there is
    // a connection — a mute on an unwired channel would mean nothing.
    let description = telegram.username
        ? `Active · @${telegram.username}`
        : 'Active';
    if (muted) {
        description = telegram.username
            ? `Muted · @${telegram.username}`
            : 'Muted';
    }

    return (
        <>
            <SettingsRow
                icon="mdi:telegram"
                label="Telegram"
                description={description}
                control={
                    <Toggle
                        label="Send run notifications to Telegram"
                        checked={!muted}
                        onChange={(on) => onMuteChange(!on)}
                    />
                }
            />
            <div className="-mt-1 pl-11">
                <button
                    type="button"
                    onClick={() =>
                        guard(() =>
                            router.delete('/profile/telegram', {
                                preserveScroll: true,
                            }),
                        )
                    }
                    className="focus-ring inline-flex shrink-0 items-center gap-1 rounded text-label-small text-text-3 transition hover:text-ember-ink"
                >
                    <Icon
                        icon="mdi:link-off"
                        width={13}
                        height={13}
                        aria-hidden
                    />
                    Disconnect
                </button>
            </div>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}

Settings.layout = appLayout;
