import { Head, Link, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useMemo, useState } from 'react';
import AppShell from '@/layouts/AppShell';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import HeroPanel from '@/components/ui/HeroPanel';
import PersonaBar, { type PersonaSlice } from '@/components/PersonaBar';
import PrCard from '@/components/card/PrCard';
import SectionLabel from '@/components/ui/SectionLabel';
import Temari from '@/components/temari/Temari';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { cn } from '@/lib/cn';
import PageContainer from '@/components/ui/PageContainer';
import { formatIdDate, formatNaiveIdDate, formatShortDateId, monthsSinceId } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { PR_CATEGORY_LABELS, formatPrValue } from '@/lib/pr';
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

interface TopPrEntry {
    id: number;
    category: string;
    value_sec: number;
    set_at: string;
    activity_id: number | null;
    activity_name: string | null;
}

interface UnlockEntry {
    unlock_key: string;
    unlocked_at: string;
}

interface UnlockCatalogEntry {
    name: string;
    icon: string;
    description: string;
    criteria: string;
}

interface TelegramPayload {
    connected: boolean;
    username: string | null;
    connect_url: string | null;
    notify_post_run: boolean;
    notify_weekly_recap: boolean;
}

interface AkuProps {
    identity: IdentityPayload;
    stats: StatsPayload;
    topPrs?: TopPrEntry[];
    unlocks?: UnlockEntry[];
    unlockCatalog?: Record<string, UnlockCatalogEntry>;
    personaMix?: PersonaSlice[];
    personaSummary?: AnalysisPayload;
    profileVoice?: AnalysisPayload;
    telegram?: TelegramPayload;
}

const TELEGRAM_DEFAULT: TelegramPayload = {
    connected: false,
    username: null,
    connect_url: null,
    notify_post_run: true,
    notify_weekly_recap: true,
};

export default function Aku({
    identity,
    stats,
    topPrs = [],
    unlocks = [],
    unlockCatalog = {},
    personaMix = [],
    personaSummary,
    profileVoice,
    telegram = TELEGRAM_DEFAULT,
}: Readonly<AkuProps>) {
    const sharedUser = usePage<SharedProps>().props.auth.user;
    const firstName = sharedUser?.first_name ?? identity.name.split(' ')[0] ?? '';
    const firstRunShort = identity.first_run_at ? formatShortDateId(identity.first_run_at) : null;
    const memberSince = identity.member_since ? formatIdDate(identity.member_since, 'long') : null;
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

                <HeroPanel className="lg:px-12 lg:py-10">
                    <div className="grid items-center gap-6 lg:grid-cols-[160px_1fr]">
                        <div className="flex justify-center lg:justify-start">
                            <Temari pose="proud" size={160} />
                        </div>
                        <div>
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
                            <div className="mt-5 flex flex-wrap gap-2">
                                <Chip tone="onSky">
                                    {identity.strava_connected ? 'Strava aktif' : 'Strava off'}
                                </Chip>
                                {memberSince && <Chip tone="onSky">Gabung sejak {memberSince}</Chip>}
                            </div>
                        </div>
                    </div>
                </HeroPanel>

                <section className="mt-8 grid gap-3.5 sm:grid-cols-3">
                    <StatCard
                        accent="leaf"
                        label="Total km"
                        value={stats.total_km.toFixed(1)}
                        unit="km"
                        hint="sejauh ini"
                    />
                    <StatCard
                        accent="horizon"
                        label="Total lari"
                        value={stats.total_runs.toString()}
                        unit="lari"
                        hint="bareng Temari"
                    />
                    <StatCard
                        accent="nyala"
                        label="Lari terjauh"
                        value={stats.longest_run_km.toFixed(2)}
                        unit="km"
                        hint={firstRunShort ? `sejak ${firstRunShort}` : undefined}
                    />
                </section>

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

                {topPrs.length > 0 && (
                    <section className="mt-10">
                        <SectionLabel>Rekor terbaru</SectionLabel>
                        <div className="grid gap-3.5 sm:grid-cols-3">
                            {topPrs.map((pr) => (
                                <RekorMini key={pr.id} pr={pr} />
                            ))}
                        </div>
                        <div className="mt-4 text-right">
                            <Link
                                href="/rekor"
                                className="focus-ring rounded font-mono text-[12px] font-semibold uppercase tracking-[0.14em] text-horizon-deep hover:text-ember-deep"
                            >
                                Semua rekor →
                            </Link>
                        </div>
                    </section>
                )}

                <section className="mt-10">
                    <SectionLabel>Notifikasi Telegram</SectionLabel>
                    <Card padding="lg">
                        <TelegramPanel telegram={telegram} />
                    </Card>
                </section>

                <section className="mt-10">
                    <SectionLabel>Aksesori</SectionLabel>
                    <AksesoriStrip
                        unlocks={unlocks}
                        catalog={unlockCatalog}
                    />
                </section>
            </PageContainer>
        </AppShell>
    );
}

function TelegramPanel({ telegram }: Readonly<{ telegram: TelegramPayload }>) {
    // Local state prevents a rapid-click race: if the user flips both toggles before
    // Inertia refreshes props, the second PATCH would read stale props for the first
    // toggle's value and silently revert it. Local state sees the latest flipped value.
    const [postRun, setPostRun] = useState(telegram.notify_post_run);
    const [weeklyRecap, setWeeklyRecap] = useState(telegram.notify_weekly_recap);

    const savePrefs = (notifyPostRun: boolean, notifyWeeklyRecap: boolean) => {
        router.patch(
            '/profil/telegram',
            { notify_post_run: notifyPostRun, notify_weekly_recap: notifyWeeklyRecap },
            { preserveScroll: true },
        );
    };

    if (!telegram.connected) {
        return (
            <div className="flex flex-col gap-4">
                <p className="font-display text-base italic text-ink-2">
                    Sambungin Telegram biar Temari bisa kabarin kamu tiap abis lari sama pas rekap mingguan.
                </p>
                {telegram.connect_url ? (
                    <a
                        href={telegram.connect_url}
                        className="inline-flex items-center gap-2 self-start rounded-full bg-[#229ED9] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1c8cbf]"
                    >
                        <Icon icon="mdi:telegram" width={18} height={18} aria-hidden />
                        Hubungkan Telegram
                    </a>
                ) : (
                    <p className="font-sans text-[12px] text-ink-3">Bot Telegram belum dikonfigurasi.</p>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <div className="flex items-center justify-between gap-3">
                <Chip tone="horizon">
                    Telegram aktif{telegram.username ? ` · @${telegram.username}` : ''}
                </Chip>
                <button
                    type="button"
                    onClick={() => router.delete('/profil/telegram', { preserveScroll: true })}
                    className="focus-ring rounded font-mono text-[12px] font-semibold uppercase tracking-[0.14em] text-ink-3 transition hover:text-ember-deep"
                >
                    Putuskan
                </button>
            </div>
            <div className="flex flex-col gap-3">
                <NotifyToggle
                    label="Cerita abis lari"
                    checked={postRun}
                    onChange={(value) => {
                        setPostRun(value);
                        savePrefs(value, weeklyRecap);
                    }}
                />
                <NotifyToggle
                    label="Rekap mingguan"
                    checked={weeklyRecap}
                    onChange={(value) => {
                        setWeeklyRecap(value);
                        savePrefs(postRun, value);
                    }}
                />
            </div>
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

type StatAccent = 'leaf' | 'horizon' | 'nyala';

const STAT_ACCENT: Record<StatAccent, { border: string; value: string }> = {
    leaf: { border: 'before:bg-leaf', value: 'text-leaf-deep' },
    horizon: { border: 'before:bg-horizon', value: 'text-horizon-deep' },
    nyala: { border: 'before:bg-mood-nyala', value: 'text-mood-nyala' },
};

function StatCard({
    accent,
    label,
    value,
    unit,
    hint,
}: Readonly<{ accent: StatAccent; label: string; value: string; unit?: string; hint?: string }>) {
    const tone = STAT_ACCENT[accent];
    return (
        <Card
            padding="lg"
            className={cn(
                'relative overflow-hidden',
                'before:absolute before:inset-x-0 before:top-0 before:h-1',
                tone.border,
            )}
        >
            <div className="font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                {label}
            </div>
            <div className="mt-2 flex items-baseline gap-1.5">
                <span
                    className={cn(
                        'font-sans text-display-sm font-black leading-none tabular-nums',
                        tone.value,
                    )}
                >
                    {value}
                </span>
                {unit && (
                    <span className="font-mono font-bold text-[11px] uppercase tracking-[0.14em] text-ink-2">
                        {unit}
                    </span>
                )}
            </div>
            {hint && <div className="mt-2 font-display text-sm italic text-ink-3">{hint}</div>}
        </Card>
    );
}

function RekorMini({ pr }: Readonly<{ pr: TopPrEntry }>) {
    return (
        <PrCard
            category={PR_CATEGORY_LABELS[pr.category] ?? pr.category}
            time={formatPrValue(pr.category, pr.value_sec)}
            setAt={formatNaiveIdDate(pr.set_at, 'short')}
            activityId={pr.activity_id}
        />
    );
}

function AksesoriStrip({
    unlocks,
    catalog,
}: Readonly<{
    unlocks: UnlockEntry[];
    catalog: Record<string, UnlockCatalogEntry>;
}>) {
    const { entries, unlockedKeys, unlockedCount } = useMemo(() => {
        const keys = new Set(unlocks.map((u) => u.unlock_key));
        const list = Object.entries(catalog);
        return {
            entries: list,
            unlockedKeys: keys,
            unlockedCount: list.filter(([key]) => keys.has(key)).length,
        };
    }, [unlocks, catalog]);

    if (entries.length === 0) return null;

    return (
        <Card padding="lg">
            <div className="mb-4 flex items-center justify-between">
                <Chip tone="horizon">{unlockedCount} / {entries.length} kebuka</Chip>
                <Link
                    href="/aksesori"
                    className="font-mono text-[12px] font-semibold uppercase tracking-[0.14em] text-horizon-deep hover:text-ember-deep"
                >
                    Dandanin →
                </Link>
            </div>
            <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                {entries.map(([key, meta]) => {
                    const unlocked = unlockedKeys.has(key);
                    return (
                        <article
                            key={key}
                            className={
                                unlocked
                                    ? 'flex flex-col gap-2 rounded-2xl bg-horizon/[0.08] px-4 py-4 text-ink'
                                    : 'flex flex-col gap-2 rounded-2xl border border-dashed border-cream-deep bg-cream/40 px-4 py-4 text-ink-3'
                            }
                        >
                            <span
                                className={
                                    unlocked
                                        ? 'flex h-9 w-9 items-center justify-center rounded-xl bg-horizon text-cream'
                                        : 'flex h-9 w-9 items-center justify-center rounded-xl bg-ink-3/20 text-ink-3'
                                }
                            >
                                <Icon
                                    icon={unlocked ? meta.icon : 'mdi:lock-outline'}
                                    width={18}
                                    height={18}
                                    aria-hidden
                                />
                            </span>
                            <h4 className="font-display text-base leading-tight tracking-[-0.005em] text-ink">
                                {meta.name}
                            </h4>
                            <p className="font-sans text-[11px] leading-snug text-ink-3">
                                {unlocked ? meta.description : meta.criteria}
                            </p>
                        </article>
                    );
                })}
            </div>
        </Card>
    );
}
