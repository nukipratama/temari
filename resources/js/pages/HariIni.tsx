import { Head, Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import ConfettiBurst from '@/components/ConfettiBurst';
import MilestoneBanner, { type PendingMilestone } from '@/components/MilestoneBanner';
import FirstRunTooltip from '@/components/onboarding/FirstRunTooltip';
import PageOnboardingTooltip from '@/components/onboarding/PageOnboardingTooltip';
import MetricExplainer from '@/components/MetricExplainer';
import type { MetricKey } from '@/lib/metricGlossary';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import HeroPanel from '@/components/ui/HeroPanel';
import LinkCard from '@/components/ui/LinkCard';
import Kartu from '@/components/card/Kartu';
import KartuMini from '@/components/card/KartuMini';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import TemariProto, { type TemariPose } from '@/components/temari/TemariProto';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formStatusLabel } from '@/lib/formStatus';
import { formatKm, formatPace, formatRelativeId, paceSecPerKm } from '@/lib/pace';
import { emberGlowStyle } from '@/lib/styles';
import {
    MOOD_UPPER,
    VIBE_TO_POSE,
    atlHint,
    ctlHint,
    formatIdDateUpper,
    formatSignedForm,
    formatWeather,
    kartuStripItem,
    monotonyHint,
    pickFeaturedKartu,
    poseForRun,
    shortenLocation,
    strainHint,
    vibeSubtitleFor,
    type FeaturedCard,
    type StripItem,
} from './HariIni/helpers';
import type {
    ActivityDetail,
    AnalysisPayload,
    BriefingResult,
    Mood,
    SharedProps,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

interface LastRunNote {
    oneline: string;
    mood: Mood;
}

interface HariIniProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    lastRunNote?: LastRunNote | null;
    totalKartuCount?: number;
    hasNewPr?: boolean;
    pendingMilestone?: PendingMilestone | null;
}

const ID_DATE_FMT = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

const ID_TIME_FMT = new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
});

export default function HariIni({
    briefing,
    load,
    snapshot,
    recentRuns,
    lastRunNote = null,
    totalKartuCount = 0,
    hasNewPr = false,
    pendingMilestone = null,
}: Readonly<HariIniProps>) {
    const { props } = usePage<SharedProps & HariIniProps>();
    const firstName = props.auth.user?.first_name ?? '';
    const pose: TemariPose = VIBE_TO_POSE[briefing.vibeState] ?? 'observational';

    const featured = pickFeaturedKartu(recentRuns);
    const lastRun = recentRuns[0] ?? null;
    const cardStrip = recentRuns
        .map((r) => kartuStripItem(r))
        .filter((x): x is StripItem => x !== null)
        .slice(0, 8);

    const now = new Date();
    const dateLine = `${ID_DATE_FMT.format(now)} · ${ID_TIME_FMT.format(now)} · ${briefing.vibeLabel}`;
    const vibeSubtitle = vibeSubtitleFor(briefing.vibeLabel);

    return (
        <AppShell>
            <Head title="Hari Ini" />
            <ConfettiBurst burstKey={hasNewPr ? 'pr-detected' : null} />
            <motion.div
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-10"
            >
                <PageOnboardingTooltip
                    pageKey="hariini"
                    icon="👋"
                    title="Hai, aku Temari."
                >
                    Ini tab Hari Ini. Isinya briefing kondisi kamu, lari terakhir, sama saran sesi kalau jadi lari hari ini.
                </PageOnboardingTooltip>
                <FirstRunTooltip recentRunCount={recentRuns.length} />
                <MilestoneBanner pending={pendingMilestone} />

                {/* HEADLINE */}
                <header className="grid items-end gap-9 lg:grid-cols-[1.4fr_1fr]">
                    <div>
                        <div className="mb-3.5 font-mono text-[11px] uppercase tracking-[0.18em] text-ink-3">
                            {dateLine}
                        </div>
                        <h1 className="font-display text-display-2xl text-ink">
                            Halo, {firstName} —<br />
                            <span className="italic text-horizon">{vibeSubtitle}</span>
                        </h1>
                    </div>
                    <aside className="flex flex-col gap-3.5 pb-3.5">
                        <KataTemariCompact briefing={briefing} pose={pose} />
                        <VitalChips briefing={briefing} load={load} />
                    </aside>
                </header>

                {/* HERO KARTU */}
                {featured && <FeaturedKartuPanel featured={featured} pose={pose} mascotVoice={briefing.mascotVoice} />}

                {/* 3-UP */}
                <section className="mt-4 grid gap-4 lg:grid-cols-[1.2fr_1fr_1fr]">
                    <SuggestionCard suggestion={briefing.suggestion} lastRun={lastRun} />
                    {lastRun && <LastLariCard run={lastRun} pose={poseForRun(lastRun)} note={lastRunNote} />}
                    <KondisiCard load={load} snapshot={snapshot} />
                </section>

                {/* KARTU STRIP */}
                {cardStrip.length > 0 && (
                    <section className="mt-10">
                        <header className="mb-4 flex items-end justify-between">
                            <div>
                                <SectionLabel>Kartu terakhir</SectionLabel>
                                <p className="font-display text-headline-md text-ink">
                                    Yang Temari kasih ke kamu belakangan ini.
                                </p>
                            </div>
                            <Link
                                href="/kartu"
                                className="hidden font-mono text-[12px] font-semibold uppercase tracking-[0.14em] text-horizon-deep hover:text-ember-deep sm:inline"
                            >
                                Semua {totalKartuCount > 0 ? `${totalKartuCount} ` : ''}koleksi →
                            </Link>
                        </header>
                        <div className="-mx-5 flex gap-3 overflow-x-auto px-5 pb-1 scrollbar-hide sm:-mx-8 sm:px-8 lg:-mx-14 lg:px-14">
                            {cardStrip.map((item) => (
                                <Link key={item.key} href={`/aktivitas/${item.activityId}`} className="block">
                                    <KartuMini name={item.name} rarity={item.rarity} date={item.date} />
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
            </motion.div>
        </AppShell>
    );
}

function KataTemariCompact({ briefing, pose }: Readonly<{ briefing: BriefingResult; pose: TemariPose }>) {
    return (
        <Card padding="lg" className="flex items-start gap-3.5">
            <TemariProto pose={pose} size={48} animate={false} />
            <div className="min-w-0 flex-1">
                <div className="mb-1.5 font-mono text-[10px] font-bold uppercase tracking-[0.14em] text-horizon-deep">
                    ★ Kata Temari hari ini
                </div>
                <AnalysisStatus
                    analysis={briefing.mascotVoice}
                    inertiaReloadProps={['briefing']}
                    size="sm"
                    renderContent={(text) => (
                        <p className="whitespace-pre-line font-display text-sm italic leading-relaxed text-ink">
                            “{text}”
                        </p>
                    )}
                />
            </div>
        </Card>
    );
}


function VitalChips({ briefing, load, onSky = false }: Readonly<{ briefing: BriefingResult; load: TrainingLoad | null; onSky?: boolean }>) {
    // Vibe primary value: use the absolute form score as a numeric proxy
    // (no dedicated numeric vibe score in the data model). Qualitative label
    // moves to the sub-line.
    const vibeValue = load?.form != null ? Math.abs(load.form).toFixed(1) : briefing.vibeLabel;
    const vibeSub = briefing.vibeLabel.toLowerCase();

    return (
        <div className="grid grid-cols-3 gap-2">
            <VitalChip
                label="Vibe"
                value={vibeValue}
                sub={vibeSub}
                tone="horizon"
                onSky={onSky}
                explainerKey="vibe_vs_mood"
            />
            <VitalChip
                label="Form"
                value={load ? formatSignedForm(load.form) : '—'}
                sub={load ? formStatusLabel(load.form_status) : ''}
                tone="leaf"
                onSky={onSky}
            />
            <VitalChip
                label="Recovery"
                value={briefing.recoveryHoursLabel ?? briefing.streakLabel ?? briefing.recoveryLabel}
                sub="dari lari terakhir"
                tone="ink"
                onSky={onSky}
            />
        </div>
    );
}

function VitalChip({
    label,
    value,
    sub,
    tone,
    onSky = false,
    explainerKey,
}: Readonly<{ label: string; value: string; sub: string; tone: 'horizon' | 'leaf' | 'ink'; onSky?: boolean; explainerKey?: MetricKey }>) {
    // Color the tiny label dot, not the number — keeps the page from feeling
    // like a paint-store sample card while still tagging the metric's family.
    const dotClass = {
        horizon: 'bg-horizon',
        leaf: 'bg-leaf',
        ink: onSky ? 'bg-cream/50' : 'bg-ink-3',
    }[tone];
    const valueClass = {
        horizon: onSky ? 'text-cream' : 'text-horizon-deep',
        leaf:    onSky ? 'text-cream' : 'text-leaf',
        ink:     onSky ? 'text-cream' : 'text-ink',
    }[tone];
    return (
        <div
            className={cn(
                'rounded-xl px-3.5 py-3',
                onSky
                    ? 'border border-cream/15 bg-cream/[0.08] backdrop-blur-sm'
                    : 'border-2 border-cream-deep bg-cream',
            )}
        >
            <div className={cn('mb-1 flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-[0.14em]', onSky ? 'text-cream/70' : 'text-ink-3')}>
                <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', dotClass)} />
                <span>{label}</span>
                {explainerKey && <MetricExplainer metricKey={explainerKey} size="xs" />}
            </div>
            <div className={cn('font-sans text-[22px] font-bold leading-none tabular-nums tracking-[-0.02em]', valueClass)}>
                {value}
            </div>
            {sub !== '' && <div className={cn('mt-1 font-display text-xs italic', onSky ? 'text-cream/65' : 'text-ink-3')}>{sub}</div>}
        </div>
    );
}

function FeaturedKartuPanel({
    featured,
    pose,
    mascotVoice,
}: Readonly<{ featured: FeaturedCard; pose: TemariPose; mascotVoice: AnalysisPayload }>) {
    return (
        <HeroPanel className="mt-8 min-h-[360px] lg:px-14 lg:py-12">
            <span
                aria-hidden
                className="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full"
                style={emberGlowStyle()}
            />
            <div className="relative grid items-center gap-8 lg:grid-cols-[240px_1fr_320px] lg:gap-12">
                <div className="hidden lg:block">
                    <TemariProto pose={pose} size={240} />
                </div>
                <div>
                    <div className="mb-4 font-mono text-[11px] font-bold uppercase tracking-[0.2em] text-horizon">
                        ★ Kartu dari Temari minggu ini
                    </div>
                    <h2 className="mb-5 font-display text-display-xl text-cream">
                        <em className="italic text-horizon">{featured.name}</em>
                    </h2>
                    <div className="mb-6 max-w-xl">
                        <AnalysisStatus
                            analysis={mascotVoice}
                            inertiaReloadProps={['briefing']}
                            allowReanalyze={false}
                            showTimestamp={false}
                            renderContent={(text) => (
                                <p className="font-display text-quote-lg italic text-cream">
                                    “{text}”
                                </p>
                            )}
                        />
                    </div>
                    <Link href={`/aktivitas/${featured.activityId}`}>
                        <PillButton tone="horizon">Lihat kartu</PillButton>
                    </Link>
                </div>
                <div className="hidden lg:block lg:rotate-[4deg]">
                    <Kartu
                        name={featured.name}
                        subtitle={featured.subtitle}
                        km={featured.km}
                        durasi={featured.durasi}
                        trimp={featured.trimp}
                        rarity={featured.rarity}
                        tags={featured.tags}
                        size="md"
                        onSky
                    />
                </div>
                {/* mobile fallback: Temari above, full Kartu below — keep the kartu-as-hero feel */}
                <div className="flex flex-col items-center gap-4 lg:hidden">
                    <TemariProto pose={pose} size={120} animate={false} />
                    <Kartu
                        name={featured.name}
                        subtitle={featured.subtitle}
                        km={featured.km}
                        durasi={featured.durasi}
                        trimp={featured.trimp}
                        rarity={featured.rarity}
                        tags={featured.tags}
                        size="md"
                        onSky
                        className="w-full max-w-md"
                    />
                </div>
            </div>
        </HeroPanel>
    );
}

/**
 * Renders the LLM suggestion as a structured 2-part block:
 *  - First paragraph = title (bold display, ends with a period).
 *  - Remaining paragraphs = body, separated by `\n\n`, rendered with
 *    `whitespace-pre-line` so paragraph breaks survive.
 * Falls back to a single paragraph if the LLM didn't follow the format.
 */
function SuggestionContent({ text }: Readonly<{ text: string }>) {
    const parts = text.split(/\n\n+/).map((s) => s.trim()).filter(Boolean);
    if (parts.length === 0) {
        return null;
    }
    const [titleRaw, ...rest] = parts;
    const title = titleRaw.replace(/^["“]|["”]$/g, '');
    const body = rest.join('\n\n');

    return (
        <div className="space-y-2.5">
            <h3 className="font-display text-display-xs leading-tight tracking-[-0.01em] text-ink">
                {title}
            </h3>
            {body !== '' && (
                <p className="whitespace-pre-line font-sans text-sm leading-relaxed text-ink-2">
                    {body}
                </p>
            )}
        </div>
    );
}

function SuggestionCard({ suggestion, lastRun }: Readonly<{ suggestion: AnalysisPayload; lastRun: ActivityDetail | null }>) {
    const { trigger, pending } = useAnalysisTrigger(suggestion, ['briefing']);
    const weatherChipLabel = lastRun
        ? formatWeather(
            lastRun.weather_temp_c ?? null,
            lastRun.weather_humidity_pct ?? null,
            lastRun.weather_rain_detected ?? null,
        )
        : null;

    return (
        <Card padding="md" as="section" className="flex flex-col gap-3">
            <SectionLabel>Saran sesi dari Temari</SectionLabel>
            <AnalysisStatus
                analysis={suggestion}
                inertiaReloadProps={['briefing']}
                allowReanalyze={false}
                renderContent={(text) => <SuggestionContent text={text} />}
            />
            {weatherChipLabel && (
                <div className="flex flex-wrap gap-1.5">
                    <Chip>{weatherChipLabel}</Chip>
                </div>
            )}
            <div className="mt-auto pt-2">
                <PillButton tone="ghost" size="sm" onClick={trigger} disabled={pending}>
                    {pending ? 'Lagi mikir…' : 'Saran lain'}
                </PillButton>
            </div>
        </Card>
    );
}

function LastLariCard({ run, pose, note }: Readonly<{ run: ActivityDetail; pose: TemariPose; note?: LastRunNote | null }>) {
    const km = formatKm(run.distance);
    const paceSec = paceSecPerKm(run.moving_time, run.distance);
    const trimp = run.trimp_edwards != null ? Math.round(run.trimp_edwards) : null;
    const dateLabel = formatRelativeId(run.start_date_local);
    const locationShort = shortenLocation(run.location_name ?? null);
    const weatherLabel = formatWeather(run.weather_temp_c ?? null, run.weather_humidity_pct ?? null, run.weather_rain_detected ?? null);

    const dateUpper = formatIdDateUpper(run.start_date_local);
    const subline = [dateUpper, note ? MOOD_UPPER[note.mood] : null].filter(Boolean).join(' · ');

    return (
        <LinkCard href={`/aktivitas/${run.activity_id}`} padding="md" className="flex flex-col gap-3">
            <SectionLabel>Lari terakhir · {dateLabel}</SectionLabel>
            <div className="flex items-start gap-3">
                <TemariProto pose={pose} size={48} />
                <div className="min-w-0 flex-1">
                    <div className="font-display text-2xl leading-tight tracking-[-0.01em] text-ink">
                        {run.name ?? 'Lari'}
                    </div>
                    {subline !== '' && (
                        <div className="mt-1 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-3">
                            {subline}
                        </div>
                    )}
                    {(locationShort || weatherLabel) && (
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[10px] uppercase tracking-[0.1em] text-ink-3">
                            {locationShort && (
                                <span className="inline-flex items-center gap-1">
                                    <Icon icon="mdi:map-marker-outline" width={11} height={11} aria-hidden />
                                    {locationShort}
                                </span>
                            )}
                            {locationShort && weatherLabel && <span aria-hidden>·</span>}
                            {weatherLabel && <span>{weatherLabel}</span>}
                        </div>
                    )}
                </div>
            </div>
            <div className="grid grid-cols-3 gap-3">
                <Stat l="KM" v={km} />
                <Stat l="PACE" v={paceSec != null ? formatPace(paceSec) : '—'} />
                <Stat l="TRIMP" v={trimp != null ? String(trimp) : '—'} />
            </div>
            {note && (
                <p className="font-display text-sm italic leading-relaxed text-ink-2">
                    “{note.oneline}”
                </p>
            )}
            <span className="mt-auto font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon-deep">
                Lihat detail →
            </span>
        </LinkCard>
    );
}

function KondisiCard({
    load,
    snapshot,
}: Readonly<{ load: TrainingLoad | null; snapshot: WeeklySnapshot | null }>) {
    const rows: ReadonlyArray<{ label: string; value: string; hint: string; color: string }> = [
        {
            label: 'Fondasi',
            value: load?.ctl_42d != null ? load.ctl_42d.toFixed(1) : '—',
            hint: ctlHint(load?.ctl_42d),
            color: 'text-leaf',
        },
        {
            label: 'Kelelahan',
            value: load?.atl_7d != null ? load.atl_7d.toFixed(1) : '—',
            hint: atlHint(load?.atl_7d),
            color: 'text-ink-2',
        },
        {
            label: 'Beban',
            value: load?.strain != null ? Math.round(load.strain).toString() : '—',
            hint: strainHint(load?.strain),
            color: 'text-horizon',
        },
        {
            label: 'Variasi',
            value: load?.monotony != null ? load.monotony.toFixed(2) : '—',
            hint: monotonyHint(load?.monotony),
            color: 'text-leaf',
        },
    ];
    return (
        <Card as="section" padding="md" className="flex flex-col gap-2.5">
            <SectionLabel>Kondisi · {snapshot ? '7 hari' : 'belum cukup data'}</SectionLabel>
            {rows.map(({ label, value, hint, color }) => (
                <div
                    key={label}
                    className="flex items-baseline justify-between border-b-2 border-dashed border-cream-deep pb-2 last:border-b-0 last:pb-0"
                >
                    <div>
                        <div className="text-[13px] font-medium text-ink">{label}</div>
                        <div className="font-display text-xs italic text-ink-3">{hint}</div>
                    </div>
                    <div
                        className={cn(
                            'font-sans text-2xl font-bold leading-none tabular-nums tracking-[-0.01em]',
                            color,
                        )}
                    >
                        {value}
                    </div>
                </div>
            ))}
            <Link
                href="/aktivitas"
                className="mt-1 font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon-deep hover:text-ember-deep"
            >
                Detail teknis →
            </Link>
        </Card>
    );
}

function Stat({ l, v }: Readonly<{ l: string; v: string }>) {
    return (
        <div>
            <div className="mb-1.5 font-mono text-[10px] font-bold uppercase tracking-[0.14em] text-ink-3">{l}</div>
            <div className="font-sans text-3xl font-black leading-none tabular-nums tracking-tight text-ink">{v}</div>
        </div>
    );
}

