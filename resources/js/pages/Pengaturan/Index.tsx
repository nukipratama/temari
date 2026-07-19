import { Head, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useCallback, useRef, useState } from 'react';
import AppShell from '@/layouts/AppShell';
import BackLink from '@/components/ui/BackLink';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import SettingsRow from '@/components/ui/SettingsRow';
import DemoBlockedModal from '@/components/DemoBlockedModal';
import PushNotificationToggle from '@/components/PushNotificationToggle';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { cn } from '@/lib/cn';

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
}

interface PengaturanProps {
    telegram?: TelegramPayload;
    notificationPrefs?: NotificationPrefs;
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
};

export default function Pengaturan({
    telegram = TELEGRAM_DEFAULT,
    notificationPrefs = PREFS_DEFAULT,
}: Readonly<PengaturanProps>) {
    return (
        <AppShell>
            <Head title="Pengaturan" />
            <PageContainer>
                <header className="mb-8">
                    <BackLink href="/profil" className="mb-3.5">
                        Aku
                    </BackLink>
                    <h1 className="font-display text-display-lg text-ink">Pengaturan</h1>
                </header>

                <section>
                    <SectionLabel>Notifikasi</SectionLabel>
                    <div className="mt-3">
                        <Card padding="lg">
                            <NotificationPrefsPanel prefs={notificationPrefs} />
                        </Card>
                    </div>
                </section>

                <PushNotificationToggle />

                <section className="mt-10">
                    <SectionLabel>Telegram</SectionLabel>
                    <div className="mt-3">
                        <Card padding="lg">
                            <TelegramPanel telegram={telegram} />
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
        </AppShell>
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

function NotificationPrefsPanel({ prefs }: Readonly<{ prefs: NotificationPrefs }>) {
    // Local state prevents a rapid-click race: if the user flips two toggles before
    // Inertia refreshes props, the second PATCH would read stale props for the first
    // toggle's value and silently revert it. Local state sees the latest flipped value.
    const [postRun, setPostRun] = useState(prefs.post_run);
    const [weeklyRecap, setWeeklyRecap] = useState(prefs.weekly_recap);
    const [monthlyRecap, setMonthlyRecap] = useState(prefs.monthly_recap);
    const { open, setOpen, guard } = useDemoGuard();

    const latestRef = useRef({ postRun, weeklyRecap, monthlyRecap });
    latestRef.current = { postRun, weeklyRecap, monthlyRecap };

    const savePrefs = useCallback(() => {
        const { postRun: pr, weeklyRecap: wr, monthlyRecap: mr } = latestRef.current;
        router.patch(
            '/profil/notifikasi',
            { post_run: pr, weekly_recap: wr, monthly_recap: mr },
            { preserveScroll: true },
        );
    }, []);

    return (
        <div className="flex flex-col gap-5">
            <p className="font-sans text-[12px] text-ink-3">Berlaku buat Telegram sama notifikasi HP.</p>
            <div className="flex flex-col gap-3">
                <NotifyToggle
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
                <NotifyToggle
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
                <NotifyToggle
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
            </div>
            <PillButton
                tone="outline"
                onClick={() => guard(() => router.post('/profil/notifikasi/test', {}, { preserveScroll: true }))}
            >
                <Icon icon="mdi:send-outline" width={14} height={14} aria-hidden />
                Kirim notifikasi tes
            </PillButton>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </div>
    );
}

function TelegramPanel({ telegram }: Readonly<{ telegram: TelegramPayload }>) {
    const { isDemo, open, setOpen, guard } = useDemoGuard();

    if (!telegram.connected) {
        if (telegram.connect_url === null) {
            return <p className="font-sans text-[12px] text-ink-3">Bot Telegram belum dikonfigurasi.</p>;
        }

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

    return (
        <>
            <div className="flex items-center justify-between gap-3">
                <span className="min-w-0 overflow-hidden">
                    <Chip tone="horizon" className="truncate">
                        Telegram aktif{telegram.username ? ` · @${telegram.username}` : ''}
                    </Chip>
                </span>
                <button
                    type="button"
                    onClick={() => guard(() => router.delete('/profil/telegram', { preserveScroll: true }))}
                    className="focus-ring inline-flex items-center gap-1 rounded font-mono text-[12px] font-semibold uppercase tracking-[0.14em] text-ink-3 transition hover:text-ember-deep"
                >
                    <Icon icon="mdi:link-off" width={13} height={13} aria-hidden />
                    Putuskan
                </button>
            </div>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}

function NotifyToggle({
    label,
    checked,
    onChange,
}: Readonly<{ label: string; checked: boolean; onChange: (value: boolean) => void }>) {
    return (
        <label className="flex items-center justify-between gap-3">
            <span className="font-display text-base text-ink">{label}</span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={label}
                onClick={() => onChange(!checked)}
                className={cn(
                    'focus-ring relative h-6 w-11 rounded-full transition',
                    checked ? 'bg-horizon' : 'bg-cream-deep',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 h-5 w-5 rounded-full bg-white transition',
                        checked ? 'left-[1.375rem]' : 'left-0.5',
                    )}
                />
            </button>
        </label>
    );
}
