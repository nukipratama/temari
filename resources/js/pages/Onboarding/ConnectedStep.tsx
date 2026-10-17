import { Download, RotateCcwClock, Scale } from 'lucide-react';

import type { IconComponent } from '@/components/ui/Icon';

import TemariMascot from '@/components/temari/TemariMascot';
import { Icon } from '@/components/ui/Icon';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';

const WHAT_LANDS: ReadonlyArray<{ icon: IconComponent; text: string }> = [
    {
        icon: RotateCcwClock,
        text: 'every run Strava already has for you is landing now, with its distance, time and pace.',
    },
    {
        icon: Download,
        text: "the deeper read (splits, HR zones, effort, and the run's card) is fetched per run, the first time you open it.",
    },
    {
        icon: Scale,
        text: 'that history is the point. it is what every run you do from here gets measured against.',
    },
];

const FACE_GLOW =
    'radial-gradient(circle, color-mix(in oklab, var(--color-horizon) 55%, transparent) 0%, color-mix(in oklab, var(--color-horizon) 24%, transparent) 42%, transparent 70%)';

export default function ConnectedStep({
    firstName,
    onContinue,
}: Readonly<{ firstName: string; onContinue: () => void }>) {
    return (
        <div
            key="connected"
            className="reveal flex flex-col items-center gap-5 py-2 text-center"
        >
            <div className="relative flex items-center justify-center">
                <div
                    aria-hidden
                    className="pointer-events-none absolute size-44 rounded-full blur-[28px]"
                    style={{ background: FACE_GLOW }}
                />
                <TemariMascot pose="blazing" size={96} drawIn />
            </div>
            <PageHero size="quote-lg" italic className="text-center">
                you&rsquo;re connected, <br />
                <em className="text-icon-accent">{firstName}.</em>
            </PageHero>

            <div className="flex w-full flex-col divide-y divide-dashed divide-border text-left [&>*]:py-3 [&>*:first-child]:pt-0 [&>*:last-child]:pb-0">
                {WHAT_LANDS.map((item) => (
                    <div key={item.text} className="flex items-start gap-3">
                        <Icon
                            icon={item.icon}
                            width={18}
                            height={18}
                            aria-hidden
                            className="mt-0.5 shrink-0 text-text-3"
                        />
                        <span className="font-sans text-xs leading-relaxed text-text-2">
                            {item.text}
                        </span>
                    </div>
                ))}
            </div>

            <PillButton
                tone="horizon"
                className="w-full justify-center"
                onClick={onContinue}
            >
                continue
            </PillButton>
        </div>
    );
}
