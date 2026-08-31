import { Head, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useState } from 'react';

import type { AnalysisPayload, SharedProps } from '@/types/inertia';

import ProgressionChart from '@/components/collection/ProgressionChart';
import MeTabs from '@/components/me/MeTabs';
import SeasonStreakPanel, {
    type SeasonSummary,
} from '@/components/me/SeasonStreakPanel';
import PersonaBar, { type PersonaSlice } from '@/components/PersonaBar';
import { type StreakSummary } from '@/components/plan/StreakPanel';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Temari from '@/components/temari/Temari';
import { Card } from '@/components/ui/card';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import HeroPanel from '@/components/ui/HeroPanel';
import { Icon } from '@/components/ui/Icon';
import LegacyCard from '@/components/ui/LegacyCard';
import LinkCard from '@/components/ui/LinkCard';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import SectionLabel from '@/components/ui/SectionLabel';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import {
    formatDurationHMS,
    formatPace,
    formatShortDateId,
    monthsSinceId,
} from '@/lib/pace';
import { PR_CATEGORY_LABELS } from '@/lib/pr';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
import { outlineChipVariants } from '@/lib/variants';

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
    training_paces: TrainingPaces | null;
}

interface SeasonStreakPayload {
    season: SeasonSummary | null;
    streak: StreakSummary;
}

interface ProfileProps {
    identity: IdentityPayload;
    stats: StatsPayload;
    personaMix?: PersonaSlice[];
    profileVoice?: AnalysisPayload;
    progressionByCategory?: Record<string, ProgressionSeries> | null;
    fitness?: FitnessPayload | null;
    seasonStreak?: SeasonStreakPayload;
}

export default function Profile({
    identity,
    stats,
    personaMix = [],
    profileVoice,
    progressionByCategory = null,
    fitness = null,
    seasonStreak,
}: Readonly<ProfileProps>) {
    const { auth, stravaSync } = usePage<SharedProps>().props;
    const sharedUser = auth.user;
    const stravaRevoked = stravaSync?.state === 'revoked';
    const firstName =
        sharedUser?.first_name ?? identity.name.split(' ')[0] ?? '';
    const firstRunShort = identity.first_run_at
        ? formatShortDateId(identity.first_run_at)
        : null;
    const monthsSinceFirstRun = monthsSinceId(identity.first_run_at);

    const eyebrowParts: string[] = ['Profile'];
    if (firstRunShort) eyebrowParts.push(`running since ${firstRunShort}`);
    if (monthsSinceFirstRun !== null)
        eyebrowParts.push(`${monthsSinceFirstRun} months`);

    const totalKmCount = useCountUp(stats.total_km);
    const totalRunsCount = useCountUp(stats.total_runs);
    const longestRunCount = useCountUp(stats.longest_run_km);
    const vdotCount = useCountUp(fitness?.vdot ?? 0);
    const thresholdPaceCount = useCountUp(fitness?.threshold_pace_sec ?? 0);
    const easyPaceCount = useCountUp(fitness?.training_paces?.easy ?? 0);
    const marathonPaceCount = useCountUp(
        fitness?.training_paces?.marathon ?? 0,
    );
    const tempoPaceCount = useCountUp(fitness?.training_paces?.threshold ?? 0);
    const intervalPaceCount = useCountUp(
        fitness?.training_paces?.interval ?? 0,
    );

    return (
        <>
            <Head title="Profile" />
            <PageContainer>
                <header className="mb-8 flex flex-col gap-5">
                    <PageHero
                        eyebrow={
                            <Eyebrow
                                token="hero"
                                tone="ink-2"
                                className="mb-3.5 lg:text-xs"
                            >
                                {eyebrowParts.join(' · ')}
                            </Eyebrow>
                        }
                    >
                        {firstName ? `${firstName} Runner,` : 'Runner,'}
                        <br />
                        <em className="italic text-horizon-ink">your story.</em>
                    </PageHero>
                    <MeTabs active="profile" />
                </header>

                <HeroPanel className="lg:px-9 lg:py-8">
                    {/* Stacks below sm: the 100px mascot plus the gap leaves only
                        ~150px of column on a 320px screen, too narrow for the
                        narration CTA beside it, which wrapped one word per line into
                        a tall, cramped pill. Side-by-side from sm up. */}
                    <div className="mb-5 flex flex-col items-start gap-4 sm:flex-row sm:items-start sm:gap-6">
                        <div className="shrink-0">
                            <Temari pose="proud" size={100} animate={false} />
                        </div>
                        <div className="w-full min-w-0 sm:flex-1 sm:self-center">
                            <Eyebrow
                                token="hero"
                                tone="horizon"
                                className="mb-3"
                            >
                                ★ What Temari says about you
                            </Eyebrow>
                            {profileVoice && (
                                <AnalysisStatus
                                    analysis={profileVoice}
                                    inertiaReloadProps={['profileVoice']}
                                    showTimestamp={false}
                                    onSky
                                    renderContent={(text) => (
                                        <p className="font-sans text-base leading-relaxed text-cream">
                                            &ldquo;
                                            {renderBold(stripEdgeQuotes(text))}
                                            &rdquo;
                                        </p>
                                    )}
                                />
                            )}
                            <div className="mt-5 flex flex-wrap items-center gap-2">
                                {stravaRevoked && (
                                    <a
                                        href="/auth/strava/redirect?from=/profile"
                                        className="focus-ring inline-flex items-center gap-1.5 rounded-full bg-strava-orange px-3 py-1 text-label-micro text-white transition hover:bg-strava-orange-hover"
                                    >
                                        <Icon
                                            icon="mdi:strava"
                                            width={12}
                                            height={12}
                                            aria-hidden
                                        />
                                        Reconnect
                                    </a>
                                )}
                            </div>
                        </div>
                        {/* Anchors the row's far right on desktop, where the quote's
                            natural width otherwise leaves the hero looking empty. */}
                        {identity.member_since && (
                            <div className="hidden shrink-0 self-center text-right lg:ml-auto lg:block">
                                <Eyebrow
                                    token="micro"
                                    tone="ink-on-sky"
                                    className="text-right"
                                >
                                    With Temari since
                                </Eyebrow>
                                <p className="mt-1 font-serif text-headline-sm text-cream">
                                    {formatShortDateId(identity.member_since)}
                                </p>
                            </div>
                        )}
                    </div>
                    <div className="mb-6" data-coachmark="profile-persona">
                        <SectionLabel onSky size="micro">
                            Persona · last 12 weeks
                        </SectionLabel>
                        <PersonaBar mix={personaMix} onSky />
                    </div>
                    <motion.div
                        variants={staggerContainer}
                        initial="hidden"
                        animate="visible"
                        data-coachmark="profile-stats"
                        className="grid grid-cols-2 gap-5 sm:grid-cols-5 justify-items-center"
                    >
                        <motion.div variants={fadeInUp}>
                            <StatTile
                                tone="plainSky"
                                size="md"
                                align="center"
                                label="Total km"
                                value={totalKmCount.toFixed(1)}
                                unit="km"
                            />
                        </motion.div>
                        <motion.div variants={fadeInUp}>
                            <StatTile
                                tone="plainSky"
                                size="md"
                                align="center"
                                label="Total runs"
                                value={Math.round(totalRunsCount).toString()}
                                unit="runs"
                            />
                        </motion.div>
                        <motion.div variants={fadeInUp}>
                            <StatTile
                                tone="plainSky"
                                size="md"
                                align="center"
                                label="Longest run"
                                value={longestRunCount.toFixed(2)}
                                unit="km"
                            />
                        </motion.div>
                        {fitness?.vdot != null && (
                            <motion.div variants={fadeInUp}>
                                <StatTile
                                    tone="plainSky"
                                    size="md"
                                    align="center"
                                    label="VDOT"
                                    value={vdotCount.toFixed(1)}
                                    explainerKey="vdot"
                                />
                            </motion.div>
                        )}
                        {fitness?.threshold_pace_sec != null && (
                            <motion.div variants={fadeInUp}>
                                <StatTile
                                    tone="plainSky"
                                    size="md"
                                    align="center"
                                    label="Threshold pace"
                                    value={formatPace(thresholdPaceCount)}
                                    unit="/km"
                                    explainerKey="threshold_pace"
                                />
                            </motion.div>
                        )}
                    </motion.div>
                </HeroPanel>

                <LinkCard
                    href="/race"
                    className="pressable mt-10 flex items-center justify-between gap-3 transition hover:border-horizon/60"
                >
                    <span className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <Icon
                            icon="mdi:flag-checkered"
                            width={16}
                            height={16}
                            aria-hidden
                        />
                        Got a race coming up?
                    </span>
                    <span className="text-label-micro text-text-3">
                        Set your race &rarr;
                    </span>
                </LinkCard>

                {seasonStreak && (
                    <SeasonStreakPanel
                        season={seasonStreak.season}
                        streak={seasonStreak.streak}
                    />
                )}

                {fitness?.training_paces && (
                    <section className="mt-10">
                        <SectionLabel>Training · pace targets</SectionLabel>
                        <Card className="mt-3 px-4 py-4">
                            <motion.div
                                variants={staggerContainer}
                                initial="hidden"
                                animate="visible"
                                className="grid grid-cols-2 gap-5 sm:grid-cols-4"
                            >
                                <motion.div variants={fadeInUp}>
                                    <StatTile
                                        tone="card"
                                        size="sm"
                                        align="center"
                                        label="Easy"
                                        value={formatPace(easyPaceCount)}
                                        unit="/km"
                                        explainerKey="pace_easy"
                                    />
                                </motion.div>
                                <motion.div variants={fadeInUp}>
                                    <StatTile
                                        tone="card"
                                        size="sm"
                                        align="center"
                                        label="Marathon"
                                        value={formatPace(marathonPaceCount)}
                                        unit="/km"
                                        explainerKey="pace_marathon"
                                    />
                                </motion.div>
                                <motion.div variants={fadeInUp}>
                                    <StatTile
                                        tone="card"
                                        size="sm"
                                        align="center"
                                        label="Tempo"
                                        value={formatPace(tempoPaceCount)}
                                        unit="/km"
                                        explainerKey="pace_tempo"
                                    />
                                </motion.div>
                                <motion.div variants={fadeInUp}>
                                    <StatTile
                                        tone="card"
                                        size="sm"
                                        align="center"
                                        label="Interval"
                                        value={formatPace(intervalPaceCount)}
                                        unit="/km"
                                        explainerKey="pace_interval"
                                    />
                                </motion.div>
                            </motion.div>
                        </Card>
                    </section>
                )}

                {progressionByCategory &&
                    Object.keys(progressionByCategory).length > 0 && (
                        <ProgressionSection
                            byCategory={progressionByCategory}
                        />
                    )}
            </PageContainer>
        </>
    );
}

const PROGRESSION_TABS = ['5km', '10km', 'half_marathon', 'marathon'] as const;
const PROGRESSION_TAB_LABEL: Record<(typeof PROGRESSION_TABS)[number], string> =
    {
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

    const worstCount = useCountUp(worst);
    const bestCount = useCountUp(best);
    const deltaCount = useCountUp(delta);

    return (
        <div data-coachmark="profile-progression" className="mt-10">
            <LegacyCard as="section" padding="hero">
                {tabs.length > 1 && (
                    <div
                        className="mb-6 flex flex-wrap items-center gap-2"
                        role="tablist"
                        aria-label="Choose distance"
                    >
                        <Eyebrow
                            as="span"
                            token="micro"
                            tone="ink-2"
                            className="mr-1"
                        >
                            Distance
                        </Eyebrow>
                        {tabs.map((c) => (
                            <button
                                key={c}
                                type="button"
                                role="tab"
                                aria-selected={c === selected}
                                onClick={() => setSelected(c)}
                                className={outlineChipVariants({
                                    selected: c === selected,
                                })}
                            >
                                {PROGRESSION_TAB_LABEL[c]}
                            </button>
                        ))}
                    </div>
                )}
                <div className="grid grid-cols-1 items-start gap-7 lg:grid-cols-[1fr_1.4fr]">
                    <div>
                        <SectionLabel>Journey · {label}</SectionLabel>
                        <p className="font-serif text-headline-sm text-foreground">
                            Then{' '}
                            <em className="italic">
                                {formatDurationHMS(Math.round(worstCount))}
                            </em>
                            , now{' '}
                            <em className="italic text-horizon-ink">
                                {formatDurationHMS(Math.round(bestCount))}
                            </em>
                        </p>
                        {delta > 0 && (
                            <p className="mt-3 font-serif text-sm italic leading-relaxed text-text-2">
                                &ldquo;
                                {formatDurationHMS(Math.round(deltaCount))}{' '}
                                faster over {series.weeks.length} weeks.&rdquo;
                            </p>
                        )}
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            <Chip>
                                &minus;
                                {formatDurationHMS(Math.round(deltaCount))}{' '}
                                total
                            </Chip>
                            {series.goal_sec != null && (
                                <Chip tone="horizon">
                                    Goal: Sub-
                                    {formatDurationHMS(series.goal_sec)}
                                </Chip>
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
            </LegacyCard>
        </div>
    );
}

Profile.layout = appLayout;
