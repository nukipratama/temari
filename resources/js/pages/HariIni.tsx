import { Head, Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useState } from 'react';
import AppShell from '@/layouts/AppShell';
import ConfettiBurst from '@/components/ConfettiBurst';
import MilestoneBanner, { type PendingMilestone } from '@/components/MilestoneBanner';
import MetricExplainer from '@/components/MetricExplainer';
import type { MetricKey } from '@/lib/metricGlossary';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import LinkCard from '@/components/ui/LinkCard';
import Kartu from '@/components/card/Kartu';
import FeaturedCardHero from '@/components/card/FeaturedCardHero';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import ProgressBar from '@/components/ui/ProgressBar';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { useAnalysisTrigger } from '@/hooks/useAnalysisTrigger';
import { cn } from '@/lib/cn';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import PageContainer from '@/components/ui/PageContainer';
import { formStatusLabel } from '@/lib/formStatus';
import { formatGoalNumber, goalProgressRatio } from '@/lib/goalProgress';
import { renderBold } from '@/lib/richText';
import { aktivitasUrl, kartuUrl } from '@/lib/routes';
import { formatKm, formatPace, formatRelativeId, paceSecPerKm } from '@/lib/pace';
import {
    MOOD_UPPER,
    VIBE_TO_POSE,
    atlHint,
    ctlHint,
    formatIdDateUpper,
    formatSignedForm,
    formatWeather,
    monotonyHint,
    pickFeaturedKartu,
    poseForRun,
    shortenLocation,
    strainHint,
    vibeSubtitleFor,
    type FeaturedCard,
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
    hasNewPr = false,
    pendingMilestone = null,
}: Readonly<HariIniProps>) {
    const { props } = usePage<SharedProps & HariIniProps>();
    const firstName = props.auth.user?.first_name ?? '';
    const pose: TemariPose = VIBE_TO_POSE[briefing.vibeState] ?? 'observational';

    const featured = pickFeaturedKartu(recentRuns);
    const lastRun = recentRuns[0] ?? null;

    const now = new Date();
    const dateLine = `${ID_DATE_FMT.format(now)} · ${ID_TIME_FMT.format(now)} · ${briefing.vibeLabel}`;
    const vibeSubtitle = vibeSubtitleFor(briefing.vibeLabel);

    return (
        <AppShell>
            <Head title="Hari Ini" />
            <ConfettiBurst burstKey={hasNewPr ? 'pr-detected' : null} />
            <PageContainer>
                <MilestoneBanner pending={pendingMilestone} />

                {/* HEADLINE */}
                <header className="grid items-end gap-9 lg:grid-cols-[1.4fr_1fr]">
                    <div>
                        <div className="mb-3.5 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-ink-2">
                            {dateLine}
                        </div>
                        <h1 className="font-display text-display-2xl text-ink">
                            Halo, {firstName}<br />
                            <span className="italic text-horizon">{vibeSubtitle}</span>
                        </h1>
                    </div>
                    <aside className="pb-3.5">
                        <KataTemariCompact briefing={briefing} pose={pose} />
                    </aside>
                </header>

                {recentRuns.length === 0 ? (
                    <EmptyRunsState />
                ) : (
                    <>
                        {/* VITAL CHIPS — above hero, full width 3-up */}
                        <section className="my-6">
                            <VitalChips briefing={briefing} load={load} />
                        </section>

                        {/* HERO KARTU */}
                        {featured && <FeaturedKartuPanel featured={featured} featuredKartuVoice={briefing.featuredKartuVoice} />}

                        {/* 3-UP */}
                        <section className="mt-8 grid gap-4 lg:grid-cols-3">
                            <SuggestionCard suggestion={briefing.suggestion} lastRun={lastRun} />
                            {lastRun && <LastLariCard run={lastRun} pose={poseForRun(lastRun)} note={lastRunNote} />}
                            <KondisiCard load={load} snapshot={snapshot} />
                        </section>

                        {/* TARGET TERDEKAT */}
                        <GoalsCard />
                    </>
                )}
            </PageContainer>
        </AppShell>
    );
}

function KataTemariCompact({ briefing, pose }: Readonly<{ briefing: BriefingResult; pose: TemariPose }>) {
    return (
        <Card padding="lg" className="flex items-start gap-3.5">
            <Temari pose={pose} size={48} animate={false} />
            <div className="min-w-0 flex-1">
                <SectionLabel dot className="mb-1.5">Kata Temari hari ini</SectionLabel>
                <AnalysisStatus
                    analysis={briefing.mascotVoice}
                    inertiaReloadProps={['briefing']}
                    size="sm"
                    renderContent={(text) => (
                        <ExpandableQuote text={text} />
                    )}
                />
            </div>
        </Card>
    );
}

function ExpandableQuote({ text }: Readonly<{ text: string }>) {
    const [expanded, setExpanded] = useState(false);
    return (
        <div>
            <p className={cn('whitespace-pre-line font-display text-base italic leading-relaxed text-ink', !expanded && 'line-clamp-3')}>
                &ldquo;{renderBold(text)}&rdquo;
            </p>
            {text.length > 150 && (
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    className="focus-ring mt-1 rounded font-mono text-[11px] font-semibold text-horizon transition hover:text-horizon/80"
                >
                    {expanded ? 'Tutup' : 'Baca selengkapnya'}
                </button>
            )}
        </div>
    );
}


function VitalChips({ briefing, load }: Readonly<{ briefing: BriefingResult; load: TrainingLoad | null }>) {
    // Vibe primary value: use the absolute form score as a numeric proxy
    // (no dedicated numeric vibe score in the data model). Qualitative label
    // moves to the sub-line.
    const vibeValue = load?.form != null ? Math.abs(load.form).toFixed(1) : briefing.vibeLabel;
    const vibeSub = briefing.vibeLabel.toLowerCase();

    return (
        <div className="grid h-full grid-cols-3 gap-3">
            <VitalChip
                label="Vibe"
                value={vibeValue}
                sub={vibeSub}
                tone="horizon"
                explainerKey="vibe_vs_mood"
            />
            <VitalChip
                label="Kesiapan"
                value={load ? formatSignedForm(load.form) : '—'}
                sub={load ? formStatusLabel(load.form_status) : ''}
                tone="leaf"
                explainerKey="form"
            />
            <VitalChip
                label="Recovery"
                value={briefing.recoveryHoursLabel ?? briefing.streakLabel ?? briefing.recoveryLabel}
                sub="dari lari terakhir"
                tone="ink"
            />
        </div>
    );
}

function VitalChip({
    label,
    value,
    sub,
    tone,
    explainerKey,
}: Readonly<{ label: string; value: string; sub: string; tone: 'horizon' | 'leaf' | 'ink'; explainerKey?: MetricKey }>) {
    // Color the tiny label dot, not the number — keeps the page from feeling
    // like a paint-store sample card while still tagging the metric's family.
    const dotClass = {
        horizon: 'bg-horizon',
        leaf: 'bg-leaf',
        ink: 'bg-ink-3',
    }[tone];
    const valueClass = {
        horizon: 'text-horizon-deep',
        leaf: 'text-leaf',
        ink: 'text-ink',
    }[tone];
    return (
        <div className="flex h-full flex-col justify-between rounded-xl border border-line bg-surface-card px-3.5 py-4">
            <SectionLabel dot dotClass={dotClass} className="mb-1">
                <span className="inline-flex items-center gap-1.5">
                    {label}
                    {explainerKey && <MetricExplainer metricKey={explainerKey} size="xs" />}
                </span>
            </SectionLabel>
            <div className={cn('min-w-0 font-sans text-[40px] font-bold leading-none tabular-nums tracking-[-0.02em]', valueClass)}>
                {value}
            </div>
            {sub !== '' && <div className="mt-1 font-display text-xs italic text-ink-3">{sub}</div>}
        </div>
    );
}

function FeaturedKartuPanel({
    featured,
    featuredKartuVoice,
}: Readonly<{ featured: FeaturedCard; featuredKartuVoice: AnalysisPayload }>) {
    return (
        <FeaturedCardHero
            eyebrow="★ Kartu dari Temari minggu ini"
            name={featured.name}
            rarity={featured.rarity}
            km={featured.km}
            stats={featured.stats}
            durasi={featured.durasi}
            badges={featured.badges}
            ctaHref={kartuUrl({ id: featured.cardId })}
            voice={
                <AnalysisStatus
                    analysis={featuredKartuVoice}
                    inertiaReloadProps={['briefing']}
                    showTimestamp={false}
                    allowReanalyze={false}
                    onSky
                    renderContent={(text) => (
                        <p className="font-display text-base italic leading-relaxed text-cream">
                            &ldquo;{renderBold(text)}&rdquo;
                        </p>
                    )}
                />
            }
            card={
                <Kartu
                    name={featured.name}
                    subtitle={featured.subtitle}
                    km={featured.km}
                    durasi={featured.durasi}
                    trimp={featured.trimp}
                    rarity={featured.rarity}
                    mood={featured.mood}
                    badges={featured.badges}
                    stats={featured.stats}
                    zonePct={featured.zonePct}
                    polyline={featured.polyline}
                    paceShape={featured.paceShape}
                    size="md"
                    className="w-full"
                />
            }
        />
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
    const title = titleRaw.replace(/^[""]|[""]$/g, '');
    const body = rest.join('\n\n');

    return (
        <div className="space-y-2.5">
            <h3 className="font-display text-display-xs leading-tight tracking-[-0.01em] text-ink">
                {renderBold(title)}
            </h3>
            {body !== '' && (
                <p className="whitespace-pre-line font-sans text-sm leading-relaxed text-ink-2">
                    {renderBold(body)}
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
        <Card padding="md" as="section" className="flex h-full flex-col gap-3">
            <SectionLabel dot className="mb-0">Saran sesi dari Temari</SectionLabel>
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
        <LinkCard href={aktivitasUrl(run)} padding="md" className="flex h-full flex-col gap-3">
            <SectionLabel dot className="mb-0">Lari terakhir · {dateLabel}</SectionLabel>
            <div className="flex items-start gap-3">
                <Temari pose={pose} size={48} />
                <div className="min-w-0 flex-1">
                    <div className="font-display text-2xl leading-tight tracking-[-0.01em] text-ink">
                        {run.name ?? 'Lari'}
                    </div>
                    {subline !== '' && (
                        <div className="mt-1 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                            {subline}
                        </div>
                    )}
                    {(locationShort || weatherLabel) && (
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono font-bold text-[11px] uppercase tracking-[0.1em] text-ink-2">
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
                <StatTile tone="plain" size="lg" align="center" label="KM" value={km} valueClassName="font-black tracking-tight text-ink" />
                <StatTile tone="plain" size="lg" align="center" label="PACE" value={paceSec != null ? `${formatPace(paceSec)}/km` : '—'} valueClassName="font-black tracking-tight text-ink" />
                <StatTile tone="plain" size="lg" align="center" label="TRIMP" value={trimp != null ? String(trimp) : '—'} valueClassName="font-black tracking-tight text-ink" />
            </div>
            {note && (
                <div className="flex flex-1 items-center gap-2 px-3 text-sm leading-relaxed text-ink-2">
                    <Icon
                        icon="mdi:comment-quote-outline"
                        width={14}
                        height={14}
                        aria-hidden
                        className="mt-0.5 shrink-0 text-leaf-deep"
                    />
                    <p className="min-w-0">{renderBold(note.oneline)}</p>
                </div>
            )}
            <span className="mt-auto font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon-deep">
                Lihat detail lari →
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
        <Card as="section" padding="md" className="flex h-full flex-col gap-3">
            <SectionLabel dot className="mb-0">Kondisi · {snapshot ? '7 hari' : 'belum cukup data'}</SectionLabel>
            {rows.map(({ label, value, hint, color }) => (
                <div
                    key={label}
                    className="flex items-baseline justify-between py-1.5 border-b border-cream-deep last:border-b-0"
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
                className="focus-ring mt-auto rounded pt-1 font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon-deep hover:text-ember-deep"
            >
                Detail teknis →
            </Link>
        </Card>
    );
}

function GoalsCard() {
    const { props } = usePage<SharedProps>();
    const summary = props.goalsSummary;

    if (!summary || summary.closest.length === 0) {
        return null;
    }

    return (
        <section className="mt-8">
            <SectionLabel>
                <span className="inline-flex items-center gap-2">
                    <Icon icon="mdi:target" width={14} height={14} aria-hidden />
                    Target terdekat
                </span>
            </SectionLabel>
            <div className="grid gap-3 sm:grid-cols-3">
                {summary.closest.map((goal) => {
                    const ratio = goalProgressRatio(goal.current, goal.target);

                    return (
                        <LinkCard key={goal.id} href="/target" padding="md" className="flex h-full flex-col gap-2">
                            <div className="font-display text-base leading-tight tracking-[-0.01em] text-ink">
                                {goal.title}
                            </div>
                            <div className="mt-auto">
                                <div className="mb-1.5 flex items-baseline justify-between">
                                    <span className="font-sans text-sm font-semibold tabular-nums text-ink">
                                        {formatGoalNumber(goal.current)}<span className="text-ink-3">/</span>{formatGoalNumber(goal.target)}
                                    </span>
                                    <span className="font-mono text-[11px] font-semibold uppercase tracking-[0.1em] text-ink-3">
                                        {goal.unit}
                                    </span>
                                </div>
                                <ProgressBar value={ratio} tone="horizon" />
                            </div>
                        </LinkCard>
                    );
                })}
            </div>
        </section>
    );
}

