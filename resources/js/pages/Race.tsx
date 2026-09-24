import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import RaceDuel, { type RaceProjection } from '@/components/race/RaceDuel';
import RaceGoalForm from '@/components/race/RaceGoalForm';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
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
 * Race leads with the goal against the projection: one duel card under a
 * compact header, then the goal form. The CTL/ATL fitness chart lives on Trends.
 */
export default function Race({ race, projection }: Readonly<RaceProps>) {
    return (
        <>
            <Head title="Race" />
            <PageContainer>
                <Eyebrow token="hero" tone="ink-2">
                    Race
                </Eyebrow>
                <div className="mt-2 flex items-center justify-between gap-3">
                    <h1 className="font-serif text-quote-lg text-foreground italic">
                        your <em className="text-horizon-ink">race.</em>
                    </h1>
                    <Link
                        href="/plan"
                        className="focus-ring inline-flex flex-none items-center gap-0.5 text-xs font-semibold text-horizon-ink"
                    >
                        plan
                        <Icon
                            icon={ArrowRight}
                            className="size-3"
                            aria-hidden
                        />
                    </Link>
                </div>

                {race ? (
                    <div className="mt-4 flex flex-col gap-3">
                        <RaceDuel race={race} projection={projection} />
                        <button
                            type="button"
                            onClick={() => router.delete('/race')}
                            className="focus-ring self-start text-label-micro text-text-2"
                        >
                            clear this race
                        </button>
                    </div>
                ) : (
                    <EmptyPanel
                        face
                        title="no race on the calendar yet."
                        body="set one below and temari will start projecting your finish time."
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
