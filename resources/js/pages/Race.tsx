import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, CalendarX, Pencil } from 'lucide-react';
import { useState } from 'react';

import RaceDuel, { type RaceProjection } from '@/components/race/RaceDuel';
import RaceGoalForm from '@/components/race/RaceGoalForm';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
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

const FORM_ID = 'race-goal-form';

const MUTED_PILL =
    'focus-ring pressable inline-flex h-8 items-center gap-1.5 rounded-full bg-muted px-3 text-label-micro text-foreground transition-colors hover:bg-accent';

/**
 * Race leads with the goal against the projection: one duel card under a
 * compact header, with the goal form folded behind "edit race". The CTL/ATL
 * fitness chart lives on Trends.
 */
export default function Race({ race, projection }: Readonly<RaceProps>) {
    const [editing, setEditing] = useState(false);
    const [confirmingClear, setConfirmingClear] = useState(false);

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
                    <>
                        <RaceDuel
                            race={race}
                            projection={projection}
                            className="mt-4"
                        />
                        <div className="mt-3 flex items-center justify-between gap-3 border-t border-border pt-3">
                            <button
                                type="button"
                                aria-expanded={editing}
                                aria-controls={FORM_ID}
                                onClick={() => setEditing((open) => !open)}
                                className={MUTED_PILL}
                            >
                                <Icon
                                    icon={Pencil}
                                    className="size-3.5"
                                    aria-hidden
                                />
                                edit race
                            </button>
                            <button
                                type="button"
                                onClick={() => setConfirmingClear(true)}
                                className="focus-ring rounded p-1 text-xs font-bold text-ember-ink transition hover:opacity-80"
                            >
                                clear race
                            </button>
                        </div>
                        <TemariNudgeModal
                            open={confirmingClear}
                            onClose={() => setConfirmingClear(false)}
                            pose="concerned"
                            title={`clear ${race.name ?? 'your race'}?`}
                            body="your plan goes back to a steady rhythm with regular deloads."
                            primaryLabel="clear race"
                            primaryIcon={CalendarX}
                            primaryClassName="bg-ember-deep text-cream hover:bg-ember-deep hover:opacity-90"
                            secondaryLabel="keep it"
                            onPrimary={() => {
                                setConfirmingClear(false);
                                router.delete('/race');
                            }}
                        />
                    </>
                ) : (
                    <div className="mt-4 flex flex-col items-start gap-3">
                        <p className="text-sm leading-relaxed text-text-2">
                            set a race and temari projects your finish from your
                            own PRs.
                        </p>
                        <button
                            type="button"
                            aria-expanded={editing}
                            aria-controls={FORM_ID}
                            onClick={() => setEditing((open) => !open)}
                            className={MUTED_PILL}
                        >
                            set a race
                        </button>
                    </div>
                )}

                <div id={FORM_ID}>
                    {editing && (
                        <RaceGoalForm
                            race={race}
                            projection={projection}
                            onSaved={() => setEditing(false)}
                            className="mt-3"
                        />
                    )}
                </div>
            </PageContainer>
        </>
    );
}

Race.layout = appLayout;
