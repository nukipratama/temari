import { Head, Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import ConfettiBurst from '@/components/ConfettiBurst';
import MilestoneBanner, { type PendingMilestone } from '@/components/MilestoneBanner';
import FirstRunTooltip from '@/components/onboarding/FirstRunTooltip';
import Chip from '@/components/daybreak/Chip';
import HeroPanel from '@/components/daybreak/HeroPanel';
import Kartu from '@/components/daybreak/Kartu';
import KartuMini from '@/components/daybreak/KartuMini';
import PillButton from '@/components/daybreak/PillButton';
import SectionLabel from '@/components/daybreak/SectionLabel';
import TemariProto, { type TemariPose } from '@/components/daybreak/TemariProto';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formStatusLabel } from '@/lib/formStatus';
import { moodFromActivity } from '@/lib/moodFromActivity';
import { formatPace, formatRelativeId } from '@/lib/pace';
import { RARITY_LABELS } from '@/lib/runcard';
import type {
    ActivityDetail,
    AnalysisPayload,
    BriefingResult,
    Rarity,
    RunCard,
    SharedProps,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

interface HariIniProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    hasNewPr?: boolean;
    pendingMilestone?: PendingMilestone | null;
}

const VIBE_TO_POSE: Record<string, TemariPose> = {
    pumped: 'pumped',
    bouncy: 'excited',
    fresh: 'proud',
    steady: 'observational',
    cooked: 'wobble',
    worn_down: 'wobble',
    stretched_thin: 'wobble',
    hibernating: 'reading',
};

const MOOD_TO_POSE: Record<string, TemariPose> = {
    nyala: 'proud',
    enteng: 'excited',
    lemes: 'wobble',
    oleng: 'wobble',
    mumet: 'wobble',
    adem: 'reading',
};

const RARITY_RANK: Record<Rarity, number> = {
    common: 0,
    uncommon: 1,
    rare: 2,
    epic: 3,
    legendary: 4,
};

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
                <FirstRunTooltip recentRunCount={recentRuns.length} />
                <MilestoneBanner pending={pendingMilestone} />

                {/* HEADLINE */}
                <header className="grid items-end gap-9 lg:grid-cols-[1.4fr_1fr]">
                    <div>
                        <div className="mb-3.5 font-mono text-[11px] uppercase tracking-[0.18em] text-ink-3">
                            {dateLine}
                        </div>
                        <h1 className="font-display text-[44px] leading-[0.95] tracking-[-0.02em] text-ink sm:text-[64px] lg:text-[96px] lg:leading-[0.92] lg:tracking-[-0.025em]">
                            Halo, {firstName} —<br />
                            <em className="not-italic text-horizon-deep">
                                <span className="italic">{vibeSubtitle}</span>
                            </em>
                        </h1>
                    </div>
                    <aside className="flex flex-col gap-3.5 pb-3.5">
                        <TemariReadCard briefing={briefing} pose={pose} />
                        <VitalChips briefing={briefing} load={load} />
                    </aside>
                </header>

                {/* HERO KARTU */}
                {featured && <FeaturedKartuPanel featured={featured} pose={pose} mascotVoice={briefing.mascotVoice} />}

                {/* 3-UP */}
                <section className="mt-8 grid gap-4 lg:grid-cols-[1.2fr_1fr_1fr]">
                    <SuggestionCard suggestion={briefing.suggestion} />
                    {lastRun && <LastLariCard run={lastRun} pose={poseForRun(lastRun)} />}
                    <KondisiCard load={load} snapshot={snapshot} />
                </section>

                {/* KARTU STRIP */}
                {cardStrip.length > 0 && (
                    <section className="mt-10">
                        <header className="mb-4 flex items-end justify-between">
                            <div>
                                <SectionLabel>Kartu terakhir</SectionLabel>
                                <p className="font-display text-2xl leading-none tracking-[-0.01em] text-ink sm:text-[32px]">
                                    Yang Temari kasih ke kamu belakangan ini.
                                </p>
                            </div>
                            <Link
                                href="/kartu"
                                className="hidden font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-horizon hover:text-horizon-deep sm:inline"
                            >
                                Semua koleksi →
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

function TemariReadCard({ briefing, pose }: Readonly<{ briefing: BriefingResult; pose: TemariPose }>) {
    return (
        <div className="flex items-center gap-3.5 rounded-2xl border border-cream-deep bg-cream px-5 py-4 shadow-sm">
            <TemariProto pose={pose} size={56} />
            <div className="min-w-0 flex-1">
                <div className="mb-1 font-mono text-[9px] font-bold uppercase tracking-[0.14em] text-horizon">
                    ★ Kata Temari hari ini
                </div>
                <AnalysisStatus
                    analysis={briefing.mascotVoice}
                    inertiaReloadProps={['briefing']}
                    renderContent={(text) => (
                        <p className="font-display text-[15px] italic leading-snug text-ink sm:text-lg">
                            “{text}”
                        </p>
                    )}
                />
            </div>
        </div>
    );
}

function VitalChips({ briefing, load }: Readonly<{ briefing: BriefingResult; load: TrainingLoad | null }>) {
    return (
        <div className="grid grid-cols-3 gap-2">
            <VitalChip
                label="Vibe"
                value={briefing.vibeLabel}
                sub={briefing.vibeEmoji}
                tone="horizon"
            />
            <VitalChip
                label="Form"
                value={load ? formatSignedForm(load.form) : '—'}
                sub={load ? formStatusLabel(load.form_status) : ''}
                tone="leaf"
            />
            <VitalChip
                label="Recovery"
                value={briefing.recoveryLabel.split(':').slice(-1)[0]?.trim() ?? briefing.recoveryLabel}
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
}: Readonly<{ label: string; value: string; sub: string; tone: 'horizon' | 'leaf' | 'ink' }>) {
    const valueClass = {
        horizon: 'text-horizon',
        leaf: 'text-leaf',
        ink: 'text-ink',
    }[tone];
    return (
        <div className="rounded-xl border border-cream-deep bg-cream px-3.5 py-3">
            <div className="mb-1 font-mono text-[9px] uppercase tracking-[0.14em] text-ink-3">{label}</div>
            <div
                className={cn(
                    'font-sans text-[22px] font-bold leading-none tabular-nums tracking-[-0.02em]',
                    valueClass,
                )}
            >
                {value}
            </div>
            {sub !== '' && <div className="mt-1 font-display text-xs italic text-ink-3">{sub}</div>}
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
                style={{ background: 'radial-gradient(circle, rgba(232,160,118,0.3) 0%, transparent 70%)' }}
            />
            <div className="relative grid items-center gap-8 lg:grid-cols-[240px_1fr_320px] lg:gap-12">
                <div className="hidden lg:block">
                    <TemariProto pose={pose} size={240} />
                </div>
                <div>
                    <div className="mb-4 font-mono text-[11px] font-bold uppercase tracking-[0.2em] text-horizon">
                        ★ Kartu dari Temari minggu ini
                    </div>
                    <h2 className="mb-5 font-display text-[48px] leading-[0.95] tracking-[-0.02em] text-cream sm:text-[64px] lg:text-[80px] lg:leading-[0.92]">
                        <em className="italic text-horizon">{featured.name}</em>
                    </h2>
                    <div className="mb-6 max-w-xl">
                        <AnalysisStatus
                            analysis={mascotVoice}
                            inertiaReloadProps={['briefing']}
                            allowReanalyze={false}
                            showTimestamp={false}
                            renderContent={(text) => (
                                <p className="font-display text-lg italic leading-snug text-cream/85 sm:text-xl">
                                    “{text}”
                                </p>
                            )}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2.5">
                        <Link href={`/aktivitas/${featured.activityId}`}>
                            <PillButton tone="horizon">Lihat kartu</PillButton>
                        </Link>
                    </div>
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

function SuggestionCard({ suggestion }: Readonly<{ suggestion: AnalysisPayload }>) {
    return (
        <section className="flex flex-col gap-3.5 rounded-[18px] bg-cream-deep px-7 py-7">
            <SectionLabel>Saran sesi dari Temari</SectionLabel>
            <AnalysisStatus
                analysis={suggestion}
                inertiaReloadProps={['briefing']}
                renderContent={(text) => (
                    <p className="font-display text-3xl leading-tight tracking-[-0.015em] text-ink sm:text-4xl">
                        {text}
                    </p>
                )}
            />
            <div className="mt-auto flex flex-wrap gap-1.5">
                <Chip tone="horizon">~ 5–8 KM</Chip>
                <Chip>Easy effort</Chip>
            </div>
        </section>
    );
}

function LastLariCard({ run, pose }: Readonly<{ run: ActivityDetail; pose: TemariPose }>) {
    const km = run.distance != null ? (run.distance / 1000).toFixed(2) : '—';
    const paceSec =
        run.distance != null && run.moving_time != null && run.distance > 0
            ? run.moving_time / (run.distance / 1000)
            : null;
    const trimp = run.trimp_edwards != null ? Math.round(run.trimp_edwards) : null;
    const dateLabel = formatRelativeId(run.start_date_local);

    return (
        <Link
            href={`/aktivitas/${run.activity_id}`}
            className="flex flex-col gap-3.5 rounded-[18px] border border-cream-deep bg-cream px-7 py-6 transition hover:-translate-y-0.5 hover:shadow-md"
        >
            <SectionLabel>Lari terakhir · {dateLabel}</SectionLabel>
            <div className="flex items-start gap-3.5">
                <TemariProto pose={pose} size={56} />
                <div>
                    <div className="font-display text-2xl leading-tight tracking-[-0.01em] text-ink">
                        {run.name ?? 'Lari'}
                    </div>
                    <div className="mt-1.5 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-3">
                        {dateLabel}
                    </div>
                </div>
            </div>
            <div className="grid grid-cols-3 gap-3.5 pt-1.5">
                <Stat l="KM" v={km} />
                <Stat l="PACE" v={paceSec != null ? formatPace(paceSec) : '—'} />
                <Stat l="TRIMP" v={trimp != null ? String(trimp) : '—'} />
            </div>
            <span className="mt-auto font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon">
                Lihat detail →
            </span>
        </Link>
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
            hint: load ? 'CTL · 42 hari' : '',
            color: 'text-leaf',
        },
        {
            label: 'Kelelahan',
            value: load?.atl_7d != null ? load.atl_7d.toFixed(1) : '—',
            hint: load ? 'ATL · 7 hari' : '',
            color: 'text-ink-2',
        },
        {
            label: 'Beban',
            value: load?.strain != null ? Math.round(load.strain).toString() : '—',
            hint: 'mingguan',
            color: 'text-horizon',
        },
        {
            label: 'Variasi',
            value: load?.monotony != null ? load.monotony.toFixed(2) : '—',
            hint: load?.monotony != null && load.monotony < 1.5 ? 'sehat' : 'tinggi',
            color: 'text-leaf',
        },
    ];
    return (
        <section className="flex flex-col gap-3 rounded-[18px] border border-cream-deep bg-cream px-7 py-6">
            <SectionLabel>Kondisi · {snapshot ? '7 hari' : 'belum cukup data'}</SectionLabel>
            {rows.map(({ label, value, hint, color }) => (
                <div
                    key={label}
                    className="flex items-baseline justify-between border-b border-dashed border-cream-deep pb-2 last:border-b-0 last:pb-0"
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
        </section>
    );
}

function Stat({ l, v }: Readonly<{ l: string; v: string }>) {
    return (
        <div>
            <div className="mb-1 font-mono text-[9px] uppercase tracking-[0.14em] text-ink-3">{l}</div>
            <div className="font-sans text-xl font-bold leading-none tabular-nums text-ink">{v}</div>
        </div>
    );
}

// ─── helpers ─────────────────────────────────────────────────────────────────

interface FeaturedCard {
    activityId: number;
    name: string;
    subtitle: string;
    km: string;
    durasi: string;
    trimp: string;
    rarity: Rarity;
    tags: ReadonlyArray<string>;
    startDate: string | null;
}

function pickFeaturedKartu(runs: ReadonlyArray<ActivityDetail>): FeaturedCard | null {
    let best: FeaturedCard | null = null;
    let bestRank = -1;
    let bestDate = '';
    for (const r of runs) {
        const card = r.activity?.runCard;
        if (!card) continue;
        const rank = RARITY_RANK[card.rarity];
        const date = r.start_date_local ?? '';
        if (rank > bestRank || (rank === bestRank && date > bestDate)) {
            best = {
                activityId: r.activity_id,
                name: card.special_move,
                subtitle: `${RARITY_LABELS[card.rarity]} · ${formatRelativeId(r.start_date_local)}`,
                km: r.distance != null ? (r.distance / 1000).toFixed(2) : '—',
                durasi: r.moving_time != null ? formatDurationShort(r.moving_time) : '—',
                trimp: r.trimp_edwards != null ? String(Math.round(r.trimp_edwards)) : '—',
                rarity: card.rarity,
                tags: (card.badges ?? []).slice(0, 2).map(prettyBadge),
                startDate: r.start_date_local,
            };
            bestRank = rank;
            bestDate = date;
        }
    }
    return best;
}

interface StripItem {
    key: string;
    activityId: number;
    name: string;
    rarity: Rarity;
    date: string;
}

function kartuStripItem(run: ActivityDetail): StripItem | null {
    const card: RunCard | undefined = run.activity?.runCard;
    if (!card) return null;
    return {
        key: `card-${card.id}`,
        activityId: run.activity_id,
        name: card.special_move,
        rarity: card.rarity,
        date: formatRelativeId(run.start_date_local),
    };
}

function formatDurationShort(secs: number): string {
    const totalMinutes = Math.round(secs / 60);
    if (totalMinutes < 60) return `${totalMinutes}m`;
    const h = Math.floor(totalMinutes / 60);
    const m = totalMinutes % 60;
    return m === 0 ? `${h}j` : `${h}j ${m}m`;
}

function formatSignedForm(form: number): string {
    return form >= 0 ? `+${form.toFixed(1)}` : form.toFixed(1);
}

function vibeSubtitleFor(label: string): string {
    return `kamu lagi ${label.toLowerCase()}.`;
}

function poseForRun(run: ActivityDetail): TemariPose {
    const mood = moodFromActivity(run);
    return MOOD_TO_POSE[mood] ?? 'observational';
}

function prettyBadge(slug: string): string {
    return slug
        .split('_')
        .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
        .join(' ');
}
