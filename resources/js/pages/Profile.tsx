import { Head, usePage } from '@inertiajs/react';

import type { HeroStat } from '@/components/profile/ProfileHero';
import type { ProgressionSeries } from '@/components/profile/ProgressionCard';
import type { ProfileSeason } from '@/components/profile/SeasonCard';
import type { TimeInZone } from '@/components/profile/TimeInZoneBar';
import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload, SharedProps } from '@/types/inertia';

import PaceTargetsCard, {
    type TrainingPaces,
} from '@/components/profile/PaceTargetsCard';
import ProfileHero from '@/components/profile/ProfileHero';
import ProgressionCard from '@/components/profile/ProgressionCard';
import RaceCard from '@/components/profile/RaceCard';
import SeasonCard from '@/components/profile/SeasonCard';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import UserAvatar from '@/components/UserAvatar';
import { appLayout } from '@/layouts/appLayout';
import { formatPace } from '@/lib/pace';

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

interface FitnessPayload {
    vdot: number | null;
    threshold_pace_sec: number | null;
    threshold_confidence: string | null;
    training_paces: TrainingPaces | null;
}

interface ProfileProps {
    identity: IdentityPayload;
    stats: StatsPayload;
    profileVoice?: AnalysisPayload;
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
    progressionByCategory = null,
    fitness = null,
    timeInZone = null,
    season = null,
    seasonWeeks = null,
}: Readonly<ProfileProps>) {
    const { auth, activeRace, stravaSync } = usePage<SharedProps>().props;
    const sharedUser = auth.user;
    const firstName =
        sharedUser?.first_name ?? identity.name.split(' ')[0] ?? '';

    const heroStats: HeroStat[] = [
        {
            icon: 'mdi:map-marker-distance',
            label: 'Total km',
            value: stats.total_km.toFixed(1),
        },
        {
            icon: 'mdi:run',
            label: 'Total runs',
            value: stats.total_runs.toString(),
        },
        {
            icon: 'mdi:trophy-outline',
            label: 'Longest run',
            value: stats.longest_run_km.toFixed(2),
        },
    ];
    if (fitness?.vdot != null) {
        heroStats.push({
            icon: 'mdi:speedometer',
            label: 'VDOT',
            value: fitness.vdot.toFixed(1),
        });
    }
    if (fitness?.threshold_pace_sec != null) {
        heroStats.push({
            icon: 'mdi:timer-outline',
            label: 'Threshold',
            value: `${formatPace(fitness.threshold_pace_sec)}/km`,
        });
    }

    return (
        <>
            <Head title="Profile" />
            <PageContainer>
                <Eyebrow token="hero" tone="ink-2">
                    Profile
                </Eyebrow>
                <header className="mt-2 mb-5 flex items-start justify-between gap-3">
                    <PageHero size="quote-lg" italic>
                        {firstName ? `${firstName},` : 'Runner,'}
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

                <div>
                    <ProfileHero
                        firstRunAt={identity.first_run_at}
                        memberSince={identity.member_since}
                        voice={profileVoice}
                        timeInZone={timeInZone}
                        stats={heroStats}
                        action={
                            stravaSync?.state === 'revoked' ? (
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
                            ) : undefined
                        }
                    />
                </div>

                <div className="mt-4">
                    <RaceCard race={activeRace ?? null} />
                </div>

                <div className="mt-4">
                    <SeasonCard season={season} weeks={seasonWeeks ?? []} />
                </div>

                {fitness?.training_paces && (
                    <div className="mt-4">
                        <PaceTargetsCard paces={fitness.training_paces} />
                    </div>
                )}

                {progressionByCategory &&
                    Object.keys(progressionByCategory).length > 0 && (
                        <div className="mt-4">
                            <ProgressionCard
                                byCategory={progressionByCategory}
                            />
                        </div>
                    )}
            </PageContainer>
        </>
    );
}

Profile.layout = appLayout;
