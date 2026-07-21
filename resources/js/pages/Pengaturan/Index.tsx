import { Head, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { type ReactNode, useCallback, useRef, useState } from 'react';
import { appLayout } from '@/layouts/appLayout';
import Card from '@/components/ui/Card';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import SettingsRow from '@/components/ui/SettingsRow';
import Toggle from '@/components/ui/Toggle';
import DemoBlockedModal from '@/components/DemoBlockedModal';
import PushNotificationToggle from '@/components/PushNotificationToggle';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { cooldownAriaLabel, useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { usePendingPost } from '@/hooks/usePendingPost';
import { formatDurationHMS } from '@/lib/pace';

// The demo account can't be deleted; the backend guard rejects it and the
// shared ErrorBanner surfaces the reason, so the confirm modal stays generic.

interface TelegramPayload {
    connected: boolean;
    username: string | null;
    connect_url: string | null;
}

interface NotificationPrefs {
    post_run: boolean;
    weekly_recap: boolean;
    monthly_recap: boolean;
    /** Per-channel mutes: off means wired but silent, not disconnected. */
    telegram_enabled: boolean;
    push_enabled: boolean;
}

interface PengaturanProps {
    telegram?: TelegramPayload;
    notificationPrefs?: NotificationPrefs;
    /** Seconds left on the test-send cooldown, or null when it is not cooling. */
    testCooldownSeconds?: number | null;
}

const TELEGRAM_DEFAULT: TelegramPayload = {
    connected: false,
    username: null,
    connect_url: null,
};

const PREFS_DEFAULT: NotificationPrefs = {
    post_run: true,
    weekly_recap: true,
    monthly_recap: true,
    telegram_enabled: true,
    push_enabled: true,
};

export default function Pengaturan({
    telegram = TELEGRAM_DEFAULT,
    notificationPrefs = PREFS_DEFAULT,
    testCooldownSeconds = null,
}: Readonly<PengaturanProps>) {
    return (
        <>
            <Head title="Pengaturan" />
            <PageContainer>
                {/* No back affordance: Pengaturan is one tap from the Aku tab
                    and from the avatar menu on every page, so a breadcrumb here
                    would be chrome without a job. */}
                <header className="mb-8">
                    <PageHero eyebrow="Pengaturan" lead="Atur Temari," emph="sesuai kamu." />
                </header>

                {/* One notification section, not three. The user holds a single
                    model with two questions — what gets sent, and where it goes —
                    and splitting those across "Notifikasi", "Notifikasi HP" and
                    "Telegram" made them look unrelated. */}
                <section>
                    <SectionLabel>Notifikasi</SectionLabel>
                    <div className="mt-3">
                        <Card padding="lg">
                            <NotificationPrefsPanel
                                prefs={notificationPrefs}
                                telegram={telegram}
                                testCooldownSeconds={testCooldownSeconds}
                            />
                        </Card>
                    </div>
                </section>

                <section className="mt-10">
                    <SectionLabel>Lari</SectionLabel>
                    <div className="mt-3">
                        <Card padding="lg">
                            <SettingsRow
                                icon="mdi:heart-pulse"
                                label="Zona HR"
                                description="Atur sendiri batas Z1-Z5 biar Temari baca larimu lebih pas."
                                href="/pengaturan/zona"
                            />
                        </Card>
                    </div>
                </section>

                <section className="mt-10">
                    <SectionLabel>Akun</SectionLabel>
                    <div className="mt-3">
                        <Card padding="lg">
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
                label="Hapus akun"
                description="Hapus akun sekaligus lepasin sambungan Strava. Gak bisa dibalikin."
                tone="danger"
                onClick={() => setConfirmOpen(true)}
            />
            <TemariNudgeModal
                open={confirmOpen}
                onClose={() => setConfirmOpen(false)}
                title="Yakin mau hapus akun?"
                body={
                    <>
                        Semua lari, kartu, sama sambungan Strava kamu bakal dilepas dan gak bisa dibalikin.
                        Kalau cuma mau ganti akun Strava, ini juga caranya.
                    </>
                }
                primaryLabel="Ya, hapus akunku"
                primaryIcon="mdi:account-remove-outline"
                primaryClassName="bg-ember-deep text-cream hover:opacity-90"
                onPrimary={() => router.delete('/akun')}
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
    // Local state prevents a rapid-click race: if the user flips two toggles before
    // Inertia refreshes props, the second PATCH would read stale props for the first
    // toggle's value and silently revert it. Local state sees the latest flipped value.
    const [postRun, setPostRun] = useState(prefs.post_run);
    const [weeklyRecap, setWeeklyRecap] = useState(prefs.weekly_recap);
    const [monthlyRecap, setMonthlyRecap] = useState(prefs.monthly_recap);
    const [telegramEnabled, setTelegramEnabled] = useState(prefs.telegram_enabled);
    const [pushEnabled, setPushEnabled] = useState(prefs.push_enabled);
    const { open, setOpen, guard } = useDemoGuard();

    const latestRef = useRef({ postRun, weeklyRecap, monthlyRecap, telegramEnabled, pushEnabled });
    latestRef.current = { postRun, weeklyRecap, monthlyRecap, telegramEnabled, pushEnabled };

    // Always sends the complete state — the server validates all five as
    // required, and the toggles now live in two different groups, so a partial
    // write would leave updateOrCreate holding stale values for the other group.
    const savePrefs = useCallback(() => {
        const current = latestRef.current;
        router.patch(
            '/profil/notifikasi',
            {
                post_run: current.postRun,
                weekly_recap: current.weeklyRecap,
                monthly_recap: current.monthlyRecap,
                telegram_enabled: current.telegramEnabled,
                push_enabled: current.pushEnabled,
            },
            { preserveScroll: true },
        );
    }, []);

    return (
        <div className="flex flex-col gap-6">
            <div>
                <GroupLabel>Apa yang dikirim</GroupLabel>
                <div className="flex flex-col">
                    <SettingsRow
                        icon="mdi:message-text-outline"
                        label="Cerita abis lari"
                        control={
                            <Toggle
                                label="Cerita abis lari"
                                checked={postRun}
                                onChange={(value) =>
                                    guard(() => {
                                        setPostRun(value);
                                        latestRef.current.postRun = value;
                                        savePrefs();
                                    })
                                }
                            />
                        }
                    />
                    <SettingsRow
                        icon="mdi:calendar-week"
                        label="Rekap mingguan"
                        control={
                            <Toggle
                                label="Rekap mingguan"
                                checked={weeklyRecap}
                                onChange={(value) =>
                                    guard(() => {
                                        setWeeklyRecap(value);
                                        latestRef.current.weeklyRecap = value;
                                        savePrefs();
                                    })
                                }
                            />
                        }
                    />
                    <SettingsRow
                        icon="mdi:calendar-month-outline"
                        label="Rekap bulanan"
                        control={
                            <Toggle
                                label="Rekap bulanan"
                                checked={monthlyRecap}
                                onChange={(value) =>
                                    guard(() => {
                                        setMonthlyRecap(value);
                                        latestRef.current.monthlyRecap = value;
                                        savePrefs();
                                    })
                                }
                            />
                        }
                    />
                </div>
            </div>

            <div className="border-t border-line/60 pt-5">
                <GroupLabel>Ke mana</GroupLabel>
                <div className="flex flex-col">
                    <TelegramPanel
                        telegram={telegram}
                        muted={!telegramEnabled}
                        onMuteChange={(value) =>
                            guard(() => {
                                setTelegramEnabled(!value);
                                latestRef.current.telegramEnabled = !value;
                                savePrefs();
                            })
                        }
                    />
                    <PushNotificationToggle
                        muted={!pushEnabled}
                        onMuteChange={(value) =>
                            guard(() => {
                                setPushEnabled(!value);
                                latestRef.current.pushEnabled = !value;
                                savePrefs();
                            })
                        }
                    />
                </div>
                {/* Lives with the channels rather than the types: what it proves
                    is that a channel can reach you, not that a type is on. */}
                <div className="mt-4">
                    <TestSendButton cooldownSeconds={testCooldownSeconds} guard={guard} />
                </div>
            </div>

            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </div>
    );
}

/**
 * "Kirim notifikasi tes" with the two states it was missing: in-flight, and
 * cooling. Without them a tap looked like nothing happened, and a second tap
 * either sent again or hit the route throttle as a bare 429.
 */
function TestSendButton({
    cooldownSeconds,
    guard,
}: Readonly<{ cooldownSeconds: number | null; guard: (run: () => void) => void }>) {
    const [sending, send] = usePendingPost('/profil/notifikasi/test', { preserveScroll: true });
    const remaining = useCooldownCountdown(cooldownSeconds);
    const cooling = remaining > 0;

    let label = 'Kirim notifikasi tes';
    if (cooling) {
        label = formatDurationHMS(remaining);
    } else if (sending) {
        label = 'Lagi ngirim…';
    }

    return (
        <PillButton
            tone="outline"
            disabled={sending || cooling}
            className="disabled:cursor-not-allowed disabled:opacity-60"
            onClick={() => guard(send)}
            aria-label={cooldownAriaLabel(remaining, 'kirim notifikasi tes')}
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
    return <div className="mb-2 px-2 text-label-micro font-semibold text-ink-3">{children}</div>;
}

function TelegramPanel({
    telegram,
    muted,
    onMuteChange,
}: Readonly<{ telegram: TelegramPayload; muted: boolean; onMuteChange: (muted: boolean) => void }>) {
    const { isDemo, open, setOpen, guard } = useDemoGuard();

    if (!telegram.connected) {
        if (telegram.connect_url === null) {
            return (
                <SettingsRow
                    icon="mdi:telegram"
                    label="Telegram"
                    description="Bot Telegram belum dikonfigurasi."
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
                    description="Sambungin biar Temari bisa kabarin kamu."
                    onClick={() => setOpen(true)}
                >
                    <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
                </SettingsRow>
            );
        }

        return (
            <SettingsRow
                icon="mdi:telegram"
                label="Telegram"
                description="Sambungin biar Temari bisa kabarin kamu."
                externalHref={telegram.connect_url}
            />
        );
    }

    // Mute sits beside the connection it silences, and only exists once there is
    // a connection — a mute on an unwired channel would mean nothing.
    let description = telegram.username ? `Aktif · @${telegram.username}` : 'Aktif';
    if (muted) {
        description = telegram.username ? `Dibisukan · @${telegram.username}` : 'Dibisukan';
    }

    return (
        <>
            <SettingsRow
                icon="mdi:telegram"
                label="Telegram"
                description={description}
                control={<Toggle label="Kirim ke Telegram" checked={!muted} onChange={(on) => onMuteChange(!on)} />}
            />
            <div className="-mt-1 pl-11">
                <button
                    type="button"
                    onClick={() => guard(() => router.delete('/profil/telegram', { preserveScroll: true }))}
                    className="focus-ring inline-flex shrink-0 items-center gap-1 rounded font-mono text-[12px] font-semibold uppercase tracking-[0.14em] text-ink-3 transition hover:text-ember-deep"
                >
                    <Icon icon="mdi:link-off" width={13} height={13} aria-hidden />
                    Putuskan
                </button>
            </div>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}

Pengaturan.layout = appLayout;
