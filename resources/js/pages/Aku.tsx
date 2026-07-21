import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useState } from 'react';
import { appLayout } from '@/layouts/appLayout';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import HeroPanel from '@/components/ui/HeroPanel';
import PersonaBar, { type PersonaSlice } from '@/components/PersonaBar';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import Temari from '@/components/temari/Temari';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { cn } from '@/lib/cn';
import PageContainer from '@/components/ui/PageContainer';
import ProgressionChart from '@/components/koleksi/ProgressionChart';
import { formatDurationHMS, formatPace, formatShortDateId, monthsSinceId } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
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

interface ProgressionSeries {
    category: string;
    weeks: string[];
    times_sec: Array<number | null>;
    goal_sec: number | null;
}

interface TrainingPaces {
    easy: number;
    marathon: number;
    threshold: number;
    interval: number;
}

interface FitnessPayload {
    vdot: number | null;
    threshold_pace_sec: number | null;
    threshold_confidence: string | null;
    training_paces?: TrainingPaces | null;
}

interface AkuProps {
    identity: IdentityPayload;
    stats: StatsPayload;
    personaMix?: PersonaSlice[];
    personaSummary?: AnalysisPayload;
    profileVoice?: AnalysisPayload;
    progressionByCategory?: Record<string, ProgressionSeries> | null;
    fitness?: FitnessPayload | null;
}

export default function Aku({
    identity,
    stats,
    personaMix = [],
    personaSummary,
    profileVoice,
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
        <>
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
                    {/* Stacks below sm: the 100px mascot plus the gap leaves only
                        ~150px of column on a 320px screen, which is too narrow for
                        the "Minta Temari bacain" CTA — it wrapped one word per line
                        into a tall, cramped pill. Side-by-side from sm up, where
                        there is room for both. */}
                    <div className="mb-5 flex flex-col items-start gap-4 sm:flex-row sm:items-start sm:gap-6">
                        <div className="shrink-0">
                            <Temari pose="proud" size={100} animate={false} />
                        </div>
                        <div className="w-full min-w-0 sm:flex-1 sm:self-center">
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
                                            &ldquo;{renderBold(stripEdgeQuotes(text))}&rdquo;
                                        </p>
                                    )}
                                />
                            )}
                            <div className="mt-5 flex flex-wrap items-center gap-2">
                                {stravaRevoked && (
                                    <a
                                        href="/auth/strava/redirect?from=/profil"
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

                {fitness?.training_paces && (
                    <section className="mt-10">
                        <SectionLabel>Latihan · pace target</SectionLabel>
                        <Card className="mt-3">
                            <div className="grid grid-cols-2 gap-5 sm:grid-cols-4 justify-items-center">
                                {(
                                    [
                                        ['Easy', fitness.training_paces.easy, 'pace_easy'],
                                        ['Marathon', fitness.training_paces.marathon, 'pace_marathon'],
                                        ['Tempo', fitness.training_paces.threshold, 'pace_tempo'],
                                        ['Interval', fitness.training_paces.interval, 'pace_interval'],
                                    ] as const
                                ).map(([label, paceSec, explainerKey]) => (
                                    <StatTile
                                        key={explainerKey}
                                        tone="cream"
                                        size="sm"
                                        align="center"
                                        label={label}
                                        value={formatPace(paceSec)}
                                        unit="/km"
                                        explainerKey={explainerKey}
                                    />
                                ))}
                            </div>
                        </Card>
                    </section>
                )}

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
                                        “{renderBold(stripEdgeQuotes(text))}”
                                    </p>
                                )}
                            />
                        )}
                    </Card>
                </section>

                {progressionByCategory && Object.keys(progressionByCategory).length > 0 && (
                    <ProgressionSection byCategory={progressionByCategory} />
                )}

            </PageContainer>
        </>
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

Aku.layout = appLayout;
