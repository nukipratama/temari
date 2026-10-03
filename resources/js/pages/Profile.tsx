import { Deferred, Head, usePage } from '@inertiajs/react';
import { Footprints, Gauge, Route, Timer, Trophy } from 'lucide-react';

import type { HeroStat } from '@/components/profile/ProfileHero';
import type { ProgressionSeries } from '@/components/profile/ProgressionCard';
import type { ProfileSeason } from '@/components/profile/SeasonCard';
import type { TimeInZone } from '@/components/profile/TimeInZoneBar';
import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload, Mood, SharedProps } from '@/types/inertia';

import AiOutageBanner from '@/components/AiOutageBanner';
import PaceTargetsCard, {
    type TrainingPaces,
    type VdotSource,
    type WeekSession,
} from '@/components/profile/PaceTargetsCard';
import ProfileHero from '@/components/profile/ProfileHero';
import ProgressionCard from '@/components/profile/ProgressionCard';
import RaceCard from '@/components/profile/RaceCard';
import SeasonCard from '@/components/profile/SeasonCard';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon, StravaIcon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { SkeletonChart, SkeletonRows } from '@/components/ui/Skeleton';
import UserAvatar from '@/components/UserAvatar';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';
import { laneStack, pillButtonVariants } from '@/lib/variants';

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
    has_activity: boolean;
}

interface FitnessPayload {
    vdot: number | null;
    quality_vdot: number | null;
    vdot_source: VdotSource | null;
    threshold_pace_sec: number | null;
    threshold_confidence: string | null;
    training_paces: TrainingPaces | null;
    week_sessions: WeekSession[];
}

interface ProfileProps {
    identity: IdentityPayload;
    stats: StatsPayload;
    profileVoice?: AnalysisPayload;
    mood: Mood;
    progressionByCategory?: Record<string, ProgressionSeries> | null;
    fitness?: FitnessPayload | null;
    timeInZone?: TimeInZone | null;
    season?: ProfileSeason | null;
    seasonWeeks?: SeasonSummaryWeek[] | null;
}

export default function Profile({
    identity,
    stats,
    profileVoice,
    mood,
    progressionByCategory,
    fitness,
    timeInZone,
    season,
    seasonWeeks,
}: Readonly<ProfileProps>) {
    const { auth, activeRace, stravaSync } = usePage<SharedProps>().props;
    const sharedUser = auth.user;
    const firstName =
        sharedUser?.first_name ?? identity.name.split(' ')[0] ?? '';

    const heroStats: HeroStat[] = stats.has_activity
        ? [
              {
                  icon: Route,
                  label: 'Total km',
                  value: stats.total_km.toFixed(1),
              },
              {
                  icon: Footprints,
                  label: 'Total runs',
                  value: stats.total_runs.toString(),
              },
              {
                  icon: Trophy,
                  label: 'Longest run',
                  value: `${stats.longest_run_km.toFixed(1)} km`,
              },
          ]
        : [];
    if (fitness?.vdot != null) {
        heroStats.push({
            icon: Gauge,
            label: 'VDOT',
            value: fitness.vdot.toFixed(1),
        });
    }
    if (fitness?.threshold_pace_sec != null) {
        heroStats.push({
            icon: Timer,
            label: 'Threshold',
            value: `${formatPace(fitness.threshold_pace_sec)}/km`,
        });
    }

    return (
        <>
            <Head title="Profile" />
            <AiOutageBanner />
            <PageContainer>
                <Eyebrow token="hero" tone="ink-2">
                    Profile
                </Eyebrow>
                <header className="mt-2 mb-5 flex items-start justify-between gap-3">
                    <PageHero size="quote-lg" italic>
                        {firstName ? `${firstName},` : 'runner,'}
                        <br />
                        <em className="italic text-horizon-ink">your story.</em>
                    </PageHero>
                    <UserAvatar
                        name={identity.name}
                        avatarUrl={identity.avatar_url}
                        size="lg"
                        className="mt-1.5 flex-none ring-2 ring-icon-accent"
                    />
                </header>

                <div className={cn('mt-6', laneStack)}>
                    <ProfileHero
                        mood={mood}
                        firstRunAt={identity.first_run_at}
                        voice={profileVoice}
                        timeInZone={timeInZone}
                        stats={heroStats}
                        action={
                            stravaSync?.state === 'revoked' ? (
                                <a
                                    href="/auth/strava/redirect?from=/profile"
                                    className={pillButtonVariants({
                                        tone: 'outline',
                                        size: 'sm',
                                    })}
                                >
                                    <Icon
                                        icon={StravaIcon}
                                        width={12}
                                        height={12}
                                        aria-hidden
                                    />
                                    reconnect
                                </a>
                            ) : undefined
                        }
                    />

                    <RaceCard race={activeRace ?? null} />

                    <Deferred
                        data={['season', 'seasonWeeks']}
                        fallback={<SkeletonRows count={2} />}
                    >
                        {() => (
                            <SeasonCard
                                season={season ?? null}
                                weeks={seasonWeeks ?? []}
                            />
                        )}
                    </Deferred>

                    <Deferred
                        data="fitness"
                        fallback={<SkeletonRows count={2} />}
                    >
                        {() =>
                            fitness?.training_paces ? (
                                <PaceTargetsCard
                                    paces={fitness.training_paces}
                                    source={fitness.vdot_source}
                                    weekSessions={fitness.week_sessions}
                                />
                            ) : null
                        }
                    </Deferred>

                    <Deferred
                        data="progressionByCategory"
                        fallback={<SkeletonChart />}
                    >
                        {() =>
                            progressionByCategory &&
                            Object.keys(progressionByCategory).length > 0 ? (
                                <ProgressionCard
                                    byCategory={progressionByCategory}
                                />
                            ) : null
                        }
                    </Deferred>
                </div>
            </PageContainer>
        </>
    );
}

Profile.layout = appLayout;
