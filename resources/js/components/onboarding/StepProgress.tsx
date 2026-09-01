import { cn } from '@/lib/cn';

export type OnboardingStep = 'connected' | 'preferences' | 'goal';

const STEPS: ReadonlyArray<{ key: OnboardingStep; label: string }> = [
    { key: 'connected', label: 'welcome' },
    { key: 'preferences', label: 'training' },
    { key: 'goal', label: 'race goal' },
];

/** Number of preferences sub-questions (experience, sessions, goal, days). */
export const PREFERENCES_SUB_STEPS = 4;

function circleTone(index: number, currentIndex: number): string {
    if (index < currentIndex) {
        return 'border-icon-accent bg-icon-accent text-btn-primary-fg';
    }
    if (index === currentIndex) {
        return 'border-icon-accent text-icon-accent';
    }
    return 'border-border-strong text-foreground';
}

/**
 * The three-step overview (Welcome / Training / Race Goal) that stays
 * mounted across the whole wizard, plus a row of sub-dots under Training
 * tracking progress through its four preference questions.
 */
export default function StepProgress({
    step,
    subIndex,
}: Readonly<{ step: OnboardingStep; subIndex: number }>) {
    const currentIndex = STEPS.findIndex((s) => s.key === step);

    return (
        <div className="mb-7 flex items-start" data-testid="step-progress">
            {STEPS.map((s, index) => (
                <div key={s.key} className="contents">
                    <div className="flex flex-none flex-col items-center gap-1.5">
                        <div
                            className={cn(
                                'flex size-7 flex-none items-center justify-center rounded-full border-2 font-mono text-xs leading-none font-extrabold',
                                circleTone(index, currentIndex),
                            )}
                        >
                            {index + 1}
                        </div>
                        <span
                            className={cn(
                                'text-label-micro',
                                index === currentIndex
                                    ? 'text-icon-accent'
                                    : 'text-foreground',
                            )}
                        >
                            {s.label}
                        </span>
                        {s.key === 'preferences' && step === 'preferences' && (
                            <div className="flex gap-1">
                                {Array.from(
                                    { length: PREFERENCES_SUB_STEPS },
                                    (_, dotIndex) => (
                                        <span
                                            key={dotIndex}
                                            className={cn(
                                                'size-1 rounded-full',
                                                dotIndex <= subIndex
                                                    ? 'bg-icon-accent'
                                                    : 'bg-border-strong',
                                            )}
                                        />
                                    ),
                                )}
                            </div>
                        )}
                    </div>
                    {index < STEPS.length - 1 && (
                        <div
                            className={cn(
                                'mt-3.5 h-0.5 flex-1 rounded-full',
                                index < currentIndex
                                    ? 'bg-icon-accent'
                                    : 'bg-border-strong',
                            )}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}
