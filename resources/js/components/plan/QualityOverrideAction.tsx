import { router } from '@inertiajs/react';
import { Check, Lock } from 'lucide-react';
import { useState } from 'react';

import type { PlanDayOverride } from '@/types/inertia';

import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { showToast } from '@/hooks/useToast';

function WhyDisclosure({ reason }: Readonly<{ reason: string }>) {
    return (
        <details className="mt-1.5 text-xs leading-relaxed text-text-2">
            <summary className="pressable focus-ring w-fit cursor-pointer text-label-micro text-horizon-ink">
                why?
            </summary>
            <p className="mt-1">{reason}</p>
        </details>
    );
}

/**
 * The week's one "i'm ready" override on a day: the offer behind a confirm
 * sheet carrying the plan's own reason, the rule that refuses it, or, once
 * taken, the line saying the athlete chose it and "ease it again" until a run
 * lands. The full reason sits behind "why?".
 */
export default function QualityOverrideAction({
    sessionId,
    override,
}: Readonly<{ sessionId: number; override: PlanDayOverride }>) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const url = `/plan/sessions/${sessionId}/override`;
    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onError: (errors: Record<string, string>) => {
            if (errors.override) {
                showToast(errors.override);
            }
        },
    };

    if (override.state === 'taken') {
        return (
            <div className="mt-2">
                <p className="text-xs leading-relaxed text-text-2">
                    {override.note}
                </p>
                {override.undoable && (
                    <PillButton
                        tone="horizon"
                        size="sm"
                        className="mt-2"
                        disabled={processing}
                        onClick={() => router.delete(url, options)}
                    >
                        ease it again
                    </PillButton>
                )}
            </div>
        );
    }

    if (override.state === 'locked') {
        return (
            <div className="mt-2">
                <p className="flex items-center gap-1.5 text-label-micro text-text-2">
                    <Icon icon={Lock} className="size-3" aria-hidden />
                    can't bring back · {override.blocker}
                </p>
                <WhyDisclosure reason={override.reason} />
            </div>
        );
    }

    return (
        <div className="mt-2">
            <PillButton
                tone="horizon"
                size="sm"
                disabled={processing}
                onClick={() => setConfirming(true)}
            >
                {override.label}
            </PillButton>
            <WhyDisclosure reason={override.reason} />
            <TemariNudgeModal
                open={confirming}
                onClose={() => setConfirming(false)}
                title={override.label}
                body={`${override.reason} the coach will remember this.`}
                primaryLabel="confirm"
                primaryIcon={Check}
                secondaryLabel="cancel"
                onPrimary={() => {
                    setConfirming(false);
                    router.post(url, {}, options);
                }}
            />
        </div>
    );
}
