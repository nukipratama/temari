import { Head, Link, router } from '@inertiajs/react';
import {
    Bell,
    ChevronRight,
    Clock,
    LoaderCircle,
    LogOut,
    Send,
    Sunrise,
    Unlink,
    UserX,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import PushNotificationToggle from '@/components/PushNotificationToggle';
import AppearanceCard from '@/components/settings/AppearanceCard';
import HrZonesDisclosure, {
    type HrZonesPayload,
} from '@/components/settings/HrZonesDisclosure';
import InstallAppSection from '@/components/settings/InstallAppSection';
import IntervalsIcuPanel, {
    type IntervalsIcuPayload,
} from '@/components/settings/IntervalsIcuPanel';
import TrainingPreferencesCard, {
    type TrainingPreferencesPayload,
} from '@/components/settings/TrainingPreferencesCard';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon, TelegramIcon } from '@/components/ui/Icon';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import SettingsDisconnectLink from '@/components/ui/SettingsDisconnectLink';
import SettingsRow from '@/components/ui/SettingsRow';
import Toggle from '@/components/ui/Switch';
import {
    cooldownAriaLabel,
    useCooldownCountdown,
} from '@/hooks/useCooldownCountdown';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { usePendingPost } from '@/hooks/usePendingPost';
import { showToast } from '@/hooks/useToast';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';
import { inputVariants } from '@/lib/variants';
import { releaseDeviceSubscription } from '@/lib/webPush';

import {
    useNotificationPrefs,
    type NotificationPrefs,
} from './useNotificationPrefs';

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
    intervalsIcu?: IntervalsIcuPayload;
    notificationPrefs?: NotificationPrefs;
    /** Seconds left on the test-send cooldown, or null when it is not cooling. */
    testCooldownSeconds?: number | null;
    hrZones?: HrZonesPayload;
    trainingPreferences?: TrainingPreferencesPayload;
}

const LEGAL_ROWS: ReadonlyArray<{ href: string; label: string }> = [
    { href: '/terms', label: 'terms of use' },
    { href: '/privacy', label: 'privacy policy' },
    { href: '/training-disclaimer', label: 'training disclaimer' },
];

const TELEGRAM_DEFAULT: TelegramPayload = {
    connected: false,
    username: null,
    connect_url: null,
};

const INTERVALS_ICU_DEFAULT: IntervalsIcuPayload = {
    connected: false,
    athlete_id: null,
    broken: false,
};

const PREFS_DEFAULT: NotificationPrefs = {
    notifications_enabled: true,
    briefing_push_enabled: true,
    briefing_push_minute: null,
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
    intervalsIcu = INTERVALS_ICU_DEFAULT,
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
                        tune it <br />
                        <em className="italic text-horizon-ink">your way.</em>
                    </PageHero>
                </header>

                <LaneStack>
                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Appearance
                        </Eyebrow>
                        <div className="mt-3">
                            <AppearanceCard />
                        </div>
                    </section>

                    <InstallAppSection />

                    {/* One notification section, not three. The user holds a single
                        model with two questions — what gets sent, and where it goes —
                        and splitting those across "Notifications", "Push" and
                        "Telegram" made them look unrelated. */}
                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Notifications
                        </Eyebrow>
                        <div className="mt-3">
                            <NotificationPrefsPanel
                                prefs={notificationPrefs}
                                telegram={telegram}
                                testCooldownSeconds={testCooldownSeconds}
                            />
                        </div>
                    </section>

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Integrations
                        </Eyebrow>
                        <div className="mt-3 flex flex-col">
                            <IntervalsIcuPanel intervalsIcu={intervalsIcu} />
                        </div>
                    </section>

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Running
                        </Eyebrow>
                        {/* Preferences before the zones disclosure, as the
                            prototype orders them: the open card first, the
                            collapsed one under it. */}
                        <div className="mt-3 flex flex-col divide-y divide-dashed divide-border [&>*]:py-4 [&>*:first-child]:pt-0 [&>*:last-child]:pb-0">
                            <TrainingPreferencesCard
                                trainingPreferences={trainingPreferences}
                            />
                            <HrZonesDisclosure hrZones={hrZones} />
                        </div>
                    </section>

                    {dataUse ? (
                        <section>
                            <Eyebrow as="h2" token="small" tone="ink-2">
                                {dataUse.headline}
                            </Eyebrow>
                            <ul className="mt-3 flex list-disc flex-col gap-1.5 pl-4.5">
                                {dataUse.points.map((point) => (
                                    <li
                                        key={point}
                                        className="text-xs leading-relaxed text-text-2"
                                    >
                                        {point}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            The fine print
                        </Eyebrow>
                        <div className="mt-3">
                            {LEGAL_ROWS.map((row, index) => (
                                <Link
                                    key={row.href}
                                    href={row.href}
                                    className={cn(
                                        'pressable focus-ring flex items-center justify-between gap-2 py-3.5 font-sans text-xs font-semibold text-foreground transition hover:text-horizon-ink',
                                        index !== LEGAL_ROWS.length - 1 &&
                                            'border-b border-dashed border-border',
                                    )}
                                >
                                    {row.label}
                                    <Icon
                                        icon={ChevronRight}
                                        width={16}
                                        height={16}
                                        className="shrink-0 text-text-3"
                                        aria-hidden
                                    />
                                </Link>
                            ))}
                        </div>
                    </section>

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Account
                        </Eyebrow>
                        <AccountActions />
                    </section>
                </LaneStack>
            </PageContainer>
        </>
    );
}

/**
 * The prototype's `AccountActions`: a button pair, not a settings list — a
 * bordered full-width "Log out" that becomes an auto-width row item above
 * 900px, and a bare destructive "Delete account" beneath it.
 */
async function signOut(): Promise<void> {
    const endpoint = await releaseDeviceSubscription();

    router.post(
        '/logout',
        endpoint === undefined ? {} : { push_endpoint: endpoint },
    );
}

function AccountActions() {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const { isDemo } = useDemoGuard();

    return (
        <div className="mt-3 mb-2 flex flex-col items-center gap-3 min-[900px]:flex-row min-[900px]:justify-center">
            <button
                type="button"
                onClick={() => void signOut()}
                className="pressable focus-ring flex w-full items-center justify-center gap-2 rounded-lg border border-border-strong bg-card py-3 font-sans text-xs font-bold text-foreground transition hover:bg-cream-deep/30 min-[900px]:w-auto min-[900px]:px-6"
            >
                <Icon icon={LogOut} width={16} height={16} aria-hidden />
                log out
            </button>
            {!isDemo && (
                <>
                    <button
                        type="button"
                        onClick={() => setConfirmOpen(true)}
                        className="pressable focus-ring rounded-xs p-1 font-sans text-xs font-bold text-ember-ink transition hover:opacity-80"
                    >
                        delete account
                    </button>
                    <TemariNudgeModal
                        open={confirmOpen}
                        onClose={() => setConfirmOpen(false)}
                        pose="concerned"
                        title="sure you want to delete your account?"
                        body={
                            <>
                                your runs, cards and notes go, your Strava
                                connection is unlinked, and it can&apos;t be
                                undone. if you just want to switch Strava
                                accounts, this is also how.{' '}
                                <Link
                                    href="/privacy"
                                    className="active:opacity-70 underline underline-offset-2"
                                >
                                    what&apos;s kept afterwards
                                </Link>
                            </>
                        }
                        primaryLabel="yes, delete my account"
                        primaryIcon={UserX}
                        primaryTone="danger"
                        onPrimary={() => router.delete('/account')}
                    />
                </>
            )}
        </div>
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
                        description="post-run recaps, weekly and monthly summaries, your morning briefing, word when your race time gets quicker, a check on whether a hard run was your all-out trial, a warning when easy runs running hot will cost next week a quality session, a heads-up the day before a race and a check-in on how it went, plus a nudge when your streak's about to end."
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

const QUIET_HOURS_START_MINUTE = 22 * 60;

function minuteToClock(minute: number | null): string {
    if (minute === null) {
        return '';
    }

    const pad = (part: number) => part.toString().padStart(2, '0');

    return `${pad(Math.floor(minute / 60))}:${pad(minute % 60)}`;
}

function clockToMinute(clock: string): number | null {
    if (clock === '') {
        return null;
    }

    return Number(clock.slice(0, 2)) * 60 + Number(clock.slice(3, 5));
}

/**
 * Saved when the field is left rather than on every change, since a native
 * time field reports each completed segment as it is typed. A time inside the
 * evening quiet hours is put back, as the server refuses it.
 */
function BriefingSendTime({
    minute,
    disabled,
    onChange,
}: Readonly<{
    minute: number | null;
    disabled: boolean;
    onChange: (minute: number | null) => void;
}>) {
    const [draft, setDraft] = useState(() => minuteToClock(minute));
    const [reverted, setReverted] = useState(false);

    return (
        <SettingsRow
            icon={Clock}
            label="send at"
            description="empty = follows your runs"
            control={
                <span className="flex shrink-0 items-center gap-2">
                    {draft !== '' && (
                        <button
                            type="button"
                            disabled={disabled}
                            onClick={() => {
                                setDraft('');
                                setReverted(false);
                                onChange(null);
                            }}
                            className="focus-ring rounded-sm font-sans text-xs text-text-2 underline-offset-2 hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            clear
                        </button>
                    )}
                    <input
                        type="time"
                        step={900}
                        aria-label="send the morning briefing at"
                        value={draft}
                        disabled={disabled}
                        onChange={(event) => {
                            setDraft(event.target.value);
                            setReverted(false);
                        }}
                        onBlur={() => {
                            const next = clockToMinute(draft);
                            if (
                                next !== null &&
                                next >= QUIET_HOURS_START_MINUTE
                            ) {
                                setDraft(minuteToClock(minute));
                                setReverted(true);
                            } else if (next !== minute) {
                                onChange(next);
                            }
                        }}
                        className={cn(
                            inputVariants({ size: 'sm' }),
                            'w-auto font-mono tabular-nums disabled:cursor-not-allowed disabled:opacity-50',
                        )}
                    />
                </span>
            }
        >
            {reverted && (
                <p className="text-right font-sans text-xs text-text-2">
                    before 22:00 only
                </p>
            )}
        </SettingsRow>
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

    let label = 'send test notification';
    if (cooling) {
        label = formatDurationHMS(remaining);
    } else if (sending) {
        label = 'sending…';
    }

    return (
        <PillButton
            tone="outline"
            disabled={sending || cooling}
            className="w-full justify-center disabled:cursor-not-allowed disabled:opacity-60"
            onClick={() => guard(send)}
            aria-label={cooldownAriaLabel(
                remaining,
                'sending a test notification',
            )}
        >
            <Icon
                icon={sending ? LoaderCircle : Send}
                width={14}
                height={14}
                className={sending ? 'animate-spin' : undefined}
                aria-hidden
            />
            {label}
        </PillButton>
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

Settings.layout = appLayout;
