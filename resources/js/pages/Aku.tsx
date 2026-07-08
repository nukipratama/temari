import { Head, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useCallback, useRef, useState } from 'react';
import AppShell from '@/layouts/AppShell';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import HeroPanel from '@/components/ui/HeroPanel';
import PersonaBar, { type PersonaSlice } from '@/components/PersonaBar';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import SettingsRow from '@/components/ui/SettingsRow';
import StatTile from '@/components/ui/StatTile';
import Temari from '@/components/temari/Temari';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import DemoBlockedModal from '@/components/DemoBlockedModal';
import { useDemoGuard } from '@/hooks/useDemoGuard';
import { cn } from '@/lib/cn';
import PageContainer from '@/components/ui/PageContainer';
import ProgressionChart from '@/components/koleksi/ProgressionChart';
import { formatDurationHMS, formatPace, formatShortDateId, monthsSinceId } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { PR_CATEGORY_LABELS } from '@/lib/pr';
import type { AnalysisPayload, SharedProps } from '@/types/inertia';

interface IdentityPayload {
    name: string;
    avatar_url: string | null;
    first_run_at: string | null;
    member_since: string | null;
    strava_connected: boolean;
}

interface StatsPayload {
    total_runs: number;
    total_km: number;
    longest_run_km: number;
}

interface TelegramPayload {
    connected: boolean;
    username: string | null;
    connect_url: string | null;
    notify_post_run: boolean;
    notify_weekly_recap: boolean;
    notify_monthly_recap: boolean;
    notify_daily_briefing: boolean;
}

interface ProgressionSeries {
    category: string;
    weeks: string[];
    times_sec: Array<number | null>;
    goal_sec: number | null;
}

interface FitnessPayload {
    vdot: number | null;
    threshold_pace_sec: number | null;
    threshold_confidence: string | null;
}

interface AkuProps {
    identity: IdentityPayload;
    stats: StatsPayload;
    personaMix?: PersonaSlice[];
    personaSummary?: AnalysisPayload;
    profileVoice?: AnalysisPayload;
    telegram?: TelegramPayload;
    progressionByCategory?: Record<string, ProgressionSeries> | null;
    fitness?: FitnessPayload | null;
}

const TELEGRAM_DEFAULT: TelegramPayload = {
    connected: false,
    username: null,
    connect_url: null,
    notify_post_run: true,
    notify_weekly_recap: true,
    notify_monthly_recap: true,
    notify_daily_briefing: false,
};

export default function Aku({
    identity,
    stats,
    personaMix = [],
    personaSummary,
    profileVoice,
    telegram = TELEGRAM_DEFAULT,
    progressionByCategory = null,
    fitness = null,
}: Readonly<AkuProps>) {
    const { auth, stravaSync } = usePage<SharedProps>().props;
    const sharedUser = auth.user;
    const stravaRevoked = stravaSync?.state === 'revoked';
    const firstName = sharedUser?.first_name ?? identity.name.split(' ')[0] ?? '';
    const firstRunShort = identity.first_run_at ? formatShortDateId(identity.first_run_at) : null;
    const monthsSinceFirstRun = monthsSinceId(identity.first_run_at);

    const eyebrowParts: string[] = ['Aku'];
    if (firstRunShort) eyebrowParts.push(`berlari sejak ${firstRunShort}`);
    if (monthsSinceFirstRun !== null) eyebrowParts.push(`${monthsSinceFirstRun} bulan`);

    return (
        <AppShell>
            <Head title="Aku" />
            <PageContainer>
                <header className="mb-8">
                    <div className="mb-3.5 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-ink-2 lg:text-xs">
                        {eyebrowParts.join(' · ')}
                    </div>
                    <h1 className="font-display text-display-lg text-ink">
                        {firstName ? `${firstName} Runner,` : 'Aku,'}<br />
                        <em className="italic text-horizon-deep">ceritanya.</em>
                    </h1>
                </header>

                <HeroPanel className="lg:px-9 lg:py-8">
                    <div className="mb-5 flex items-start gap-6">
                        <div className="shrink-0">
                            <Temari pose="proud" size={100} animate={false} />
                        </div>
                        <div className="min-w-0 flex-1 self-center">
                            <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.2em] text-horizon">
                                ★ Kata Temari tentang kamu
                            </div>
                            {profileVoice && (
                                <AnalysisStatus
                                    analysis={profileVoice}
                                    inertiaReloadProps={['profileVoice']}
                                    showTimestamp={false}
                                    onSky
                                    renderContent={(text) => (
                                        <p className="font-display text-base italic leading-relaxed text-cream">
                                            &ldquo;{renderBold(text)}&rdquo;
                                        </p>
                                    )}
                                />
                            )}
                            <div className="mt-5 flex flex-wrap items-center gap-2">
                                {stravaRevoked && (
                                    <a
                                        href="/auth/strava/redirect"
                                        className="focus-ring inline-flex items-center gap-1.5 rounded-full bg-strava-orange px-3 py-1 font-mono text-[11px] font-semibold uppercase tracking-[0.1em] text-white transition hover:bg-strava-orange-hover"
                                    >
                                        <Icon icon="mdi:strava" width={12} height={12} aria-hidden />
                                        Sambungin lagi
                                    </a>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-5 sm:grid-cols-5 justify-items-center">
                        <StatTile tone="plainSky" size="md" align="center" label="Total km" value={stats.total_km.toFixed(1)} unit="km" />
                        <StatTile tone="plainSky" size="md" align="center" label="Total lari" value={stats.total_runs.toString()} unit="lari" />
                        <StatTile tone="plainSky" size="md" align="center" label="Lari terjauh" value={stats.longest_run_km.toFixed(2)} unit="km" />
                        {fitness?.vdot != null && (
                            <StatTile tone="plainSky" size="md" align="center" label="VDOT" value={fitness.vdot.toFixed(1)} explainerKey="vdot" />
                        )}
                        {fitness?.threshold_pace_sec != null && (
                            <StatTile tone="plainSky" size="md" align="center" label="Threshold pace" value={formatPace(fitness.threshold_pace_sec)} unit="/km" explainerKey="threshold_pace" />
                        )}
                    </div>
                </HeroPanel>

                <section className="mt-10">
                    <SectionLabel>Persona · 12 minggu terakhir</SectionLabel>
                    <Card className="flex flex-col gap-5">
                        <PersonaBar mix={personaMix} />
                        {personaSummary && (
                            <AnalysisStatus
                                analysis={personaSummary}
                                inertiaReloadProps={['personaSummary']}
                                renderContent={(text) => (
                                    <p className="font-display text-quote-md italic text-ink-2">
                                        “{renderBold(text)}”
                                    </p>
                                )}
                            />
                        )}
                    </Card>
                </section>

                {progressionByCategory && Object.keys(progressionByCategory).length > 0 && (
                    <ProgressionSection byCategory={progressionByCategory} />
                )}

                <section className="mt-10">
                    <SectionLabel>Pengaturan</SectionLabel>
                    <div className="mt-3">
                        <Card padding="lg">
                            <TelegramPanel telegram={telegram} />
                            <div className="my-5 border-t border-line" />
                            <SettingsRow
                                icon="mdi:heart-pulse"
                                label="Zona HR"
                                description="Atur sendiri batas Z1-Z5 biar Temari baca larimu lebih pas."
                                href="/pengaturan/zona"
                            />
                        </Card>
                    </div>
                </section>
            </PageContainer>
        </AppShell>
    );
}

const PROGRESSION_TABS = ['5km', '10km', 'half_marathon', 'marathon'] as const;
const PROGRESSION_TAB_LABEL: Record<(typeof PROGRESSION_TABS)[number], string> = {
    '5km': '5K',
    '10km': '10K',
    half_marathon: 'HM',
    marathon: 'FM',
};

function ProgressionSection({
    byCategory,
}: Readonly<{ byCategory: Record<string, ProgressionSeries> }>) {
    const tabs = PROGRESSION_TABS.filter((c) => byCategory[c]);
    const [selected, setSelected] = useState<string>(tabs.at(-1) ?? tabs[0]);
    const series = byCategory[selected] ?? byCategory[tabs[0]];

    const times = series.times_sec.filter((t): t is number => t != null);
    const worst = times.length > 0 ? Math.max(...times) : 0;
    const best = times.length > 0 ? Math.min(...times) : 0;
    const delta = Math.max(0, worst - best);
    const label = PR_CATEGORY_LABELS[series.category] ?? series.category;

    return (
        <Card as="section" padding="lg" className="mt-4">
            {tabs.length > 1 && (
                <div className="mb-6 flex flex-wrap items-center gap-2" role="tablist" aria-label="Pilih jarak">
                    <span className="mr-1 font-mono font-bold text-[11px] uppercase tracking-[0.14em] text-ink-2">Jarak</span>
                    {tabs.map((c) => (
                        <button
                            key={c}
                            type="button"
                            role="tab"
                            aria-selected={c === selected}
                            onClick={() => setSelected(c)}
                            className={cn(
                                'focus-ring inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 font-mono text-[11px] font-semibold uppercase tracking-[0.1em] transition',
                                c === selected
                                    ? 'border-horizon bg-horizon/10 text-horizon-deep'
                                    : 'border-line text-ink-3 hover:border-horizon/60 hover:text-ink',
                            )}
                        >
                            {PROGRESSION_TAB_LABEL[c]}
                        </button>
                    ))}
                </div>
            )}
            <div className="grid grid-cols-1 items-center gap-7 lg:grid-cols-[1fr_1.4fr]">
                <div>
                    <SectionLabel>Perjalanan · {label}</SectionLabel>
                    <p className="font-display text-headline-sm text-ink">
                        Dulu <em className="italic">{formatDurationHMS(worst)}</em>, sekarang{' '}
                        <em className="italic text-horizon-deep">{formatDurationHMS(best)}</em>
                    </p>
                    {delta > 0 && (
                        <p className="mt-3 font-display text-sm italic leading-relaxed text-ink-2">
                            &ldquo;{formatDurationHMS(delta)} lebih kencang dalam {series.weeks.length} minggu.&rdquo;
                        </p>
                    )}
                    <div className="mt-3 flex flex-wrap gap-1.5">
                        <Chip>&minus;{formatDurationHMS(delta)} total</Chip>
                        {series.goal_sec != null && (
                            <Chip tone="horizon">Goal: Sub-{formatDurationHMS(series.goal_sec)}</Chip>
                        )}
                    </div>
                </div>
                <div>
                    <ProgressionChart
                        key={selected}
                        weeks={series.weeks}
                        timesSec={series.times_sec}
                        goalSec={series.goal_sec}
                        category={label}
                    />
                </div>
            </div>
        </Card>
    );
}

function TelegramPanel({ telegram }: Readonly<{ telegram: TelegramPayload }>) {
    // Local state prevents a rapid-click race: if the user flips both toggles before
    // Inertia refreshes props, the second PATCH would read stale props for the first
    // toggle's value and silently revert it. Local state sees the latest flipped value.
    const [postRun, setPostRun] = useState(telegram.notify_post_run);
    const [weeklyRecap, setWeeklyRecap] = useState(telegram.notify_weekly_recap);
    const [monthlyRecap, setMonthlyRecap] = useState(telegram.notify_monthly_recap);
    const [dailyBriefing, setDailyBriefing] = useState(telegram.notify_daily_briefing);
    const { isDemo, open, setOpen, guard } = useDemoGuard();

    const latest = useRef({ postRun, weeklyRecap, monthlyRecap, dailyBriefing });
    latest.current = { postRun, weeklyRecap, monthlyRecap, dailyBriefing };

    const savePrefs = useCallback(() => {
        const { postRun: pr, weeklyRecap: wr, monthlyRecap: mr, dailyBriefing: db } = latest.current;
        router.patch(
            '/profil/telegram',
            {
                notify_post_run: pr,
                notify_weekly_recap: wr,
                notify_monthly_recap: mr,
                notify_daily_briefing: db,
            },
            { preserveScroll: true },
        );
    }, []);

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
        <div className="flex flex-col gap-5">
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
            <div className="flex flex-col gap-3">
                <NotifyToggle
                    label="Cerita abis lari"
                    checked={postRun}
                    onChange={(value) =>
                        guard(() => {
                            setPostRun(value);
                            latest.current.postRun = value;
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
                            latest.current.weeklyRecap = value;
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
                            latest.current.monthlyRecap = value;
                            savePrefs();
                        })
                    }
                />
                <NotifyToggle
                    label="Ringkasan harian"
                    checked={dailyBriefing}
                    onChange={(value) =>
                        guard(() => {
                            setDailyBriefing(value);
                            latest.current.dailyBriefing = value;
                            savePrefs();
                        })
                    }
                />
            </div>
            <PillButton
                tone="outline"
                onClick={() => guard(() => router.post('/profil/telegram/test', {}, { preserveScroll: true }))}
            >
                <Icon icon="mdi:send-outline" width={14} height={14} aria-hidden />
                Kirim notifikasi tes
            </PillButton>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </div>
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
