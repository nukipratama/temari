import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import StepProgress from '@/components/onboarding/StepProgress';
import PageContainer from '@/components/ui/PageContainer';
import {
    onboardingPayload,
    useOnboardingDraft,
} from '@/hooks/useOnboardingDraft';
import { bareLayout } from '@/layouts/BareShell';

import ConnectedStep from './ConnectedStep';
import GoalStep from './GoalStep';
import NudgeStep from './NudgeStep';
import PreferencesStep from './PreferencesStep';

export default function OnboardingIndex({
    telegramConnectUrl = null,
}: Readonly<{ telegramConnectUrl?: string | null }>) {
    const page = usePage<SharedProps>().props;
    const firstName = page.auth.user?.first_name ?? '';
    const errors = page.errors ?? {};
    const [draft, dispatch] = useOnboardingDraft();
    const [processing, setProcessing] = useState(false);
    const { step, subIndex } = draft;

    const finish = () => {
        router.post('/onboarding', onboardingPayload(draft), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            // The only server-validated fields live a step behind, and
            // that step is the only one that renders their errors.
            onError: (submitErrors) => {
                if (
                    submitErrors.race_date ||
                    submitErrors.distance_m ||
                    submitErrors.goal_time_sec ||
                    submitErrors.name
                ) {
                    dispatch({ type: 'goTo', step: 'goal' });
                }
            },
        });
    };

    return (
        <>
            <Head title="Welcome" />
            <PageContainer className="pt-16 pb-10 min-[900px]:max-w-[520px] min-[900px]:pb-16 min-[1280px]:max-w-[520px]">
                <StepProgress step={step} subIndex={subIndex} />

                {step === 'connected' ? (
                    <ConnectedStep
                        firstName={firstName}
                        onContinue={() =>
                            dispatch({ type: 'goTo', step: 'preferences' })
                        }
                    />
                ) : step === 'preferences' ? (
                    <PreferencesStep draft={draft} dispatch={dispatch} />
                ) : step === 'goal' ? (
                    <GoalStep
                        draft={draft}
                        dispatch={dispatch}
                        errors={errors}
                    />
                ) : (
                    <NudgeStep
                        telegramConnectUrl={telegramConnectUrl}
                        processing={processing}
                        onBack={() => dispatch({ type: 'goTo', step: 'goal' })}
                        onFinish={finish}
                    />
                )}
            </PageContainer>
        </>
    );
}

OnboardingIndex.layout = bareLayout;
