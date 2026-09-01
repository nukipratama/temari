import { Head } from '@inertiajs/react';

import PlanRaceTabs from '@/components/race/PlanRaceTabs';
import ProjectionBlock, {
    type RaceProjection,
} from '@/components/race/ProjectionBlock';
import RaceCard from '@/components/race/RaceCard';
import RaceGoalForm from '@/components/race/RaceGoalForm';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { appLayout } from '@/layouts/appLayout';

interface RacePayload {
    id: number;
    race_date: string;
    distance_m: number;
    goal_time_sec: number;
    name: string | null;
}

interface ProjectionPayload extends RaceProjection {
    /** Fitted Riegel exponent — carried by the payload, not drawn. */
    exponent: number;
}

interface RaceProps {
    race: RacePayload | null;
    projection: ProjectionPayload | null;
}

/**
 * Race, on the frozen prototype's `RaceGoalScreen`: three blocks (P26) — the
 * race card, the projection gauge and the goal form — behind the schedule /
 * race-goal tabs. The CTL/ATL fitness chart the shipped page used to draw is
 * cut; that chart exists once, on Trends.
 */
export default function Race({ race, projection }: Readonly<RaceProps>) {
    return (
        <>
            <Head title="Race" />
            <PageContainer>
                <Eyebrow token="hero" tone="ink-2">
                    Race
                </Eyebrow>
                <PageHero size="quote-lg" italic className="mt-2">
                    {race ? (
                        <>
                            your race,
                            <br />
                            <em className="italic text-icon-accent">
                                on the calendar.
                            </em>
                        </>
                    ) : (
                        <>
                            give the plan
                            <br />
                            <em className="italic text-icon-accent">
                                something to aim at.
                            </em>
                        </>
                    )}
                </PageHero>
                <p className="mt-2 text-xs leading-relaxed text-text-2">
                    Set a race and Temari projects a realistic finish time from
                    your own PRs, then tracks your fitness trend against it.
                </p>

                <PlanRaceTabs active="race" className="mt-4" />

                {race ? (
                    <div className="mt-4 flex flex-col gap-3">
                        <RaceCard
                            name={race.name}
                            raceDate={race.race_date}
                            distanceM={race.distance_m}
                            goalTimeSec={race.goal_time_sec}
                        />
                        <ProjectionBlock projection={projection} />
                    </div>
                ) : (
                    <EmptyPanel
                        face
                        title="No race on the calendar yet."
                        body="Set one below and Temari will start projecting your finish time."
                        className="mt-4"
                    />
                )}

                <RaceGoalForm
                    race={race}
                    projection={projection}
                    className="mt-3"
                />
            </PageContainer>
        </>
    );
}

Race.layout = appLayout;
