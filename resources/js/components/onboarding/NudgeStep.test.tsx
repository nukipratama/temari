import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import * as webPush from '@/lib/webPush';

import NudgeStep from './NudgeStep';

vi.mock('@/lib/webPush');

beforeEach(() => {
    vi.mocked(webPush.isPushSupported).mockReturnValue(false);
    vi.mocked(webPush.isStandalone).mockReturnValue(true);
    vi.mocked(webPush.isIosNonSafari).mockReturnValue(false);
    vi.mocked(webPush.currentSubscription).mockResolvedValue(null);
});

function renderStep(props: Partial<Parameters<typeof NudgeStep>[0]> = {}) {
    const onBack = vi.fn();
    const onFinish = vi.fn();
    render(
        <NudgeStep
            telegramConnectUrl={null}
            processing={false}
            onBack={onBack}
            onFinish={onFinish}
            {...props}
        />,
    );

    return { onBack, onFinish };
}

describe('Onboarding/NudgeStep', () => {
    it('finishes from either the skip link or the finish button', () => {
        const { onFinish } = renderStep();

        fireEvent.click(screen.getByRole('button', { name: 'finish' }));
        fireEvent.click(screen.getByRole('button', { name: 'skip for now' }));

        expect(onFinish).toHaveBeenCalledTimes(2);
    });

    it('goes back', () => {
        const { onBack } = renderStep();

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(onBack).toHaveBeenCalledOnce();
    });

    it('disables both finish controls while saving', () => {
        renderStep({ processing: true });

        expect(screen.getByRole('button', { name: 'saving…' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'skip for now' }),
        ).toBeDisabled();
    });

    it('offers Telegram only when a connect url is given', () => {
        renderStep({ telegramConnectUrl: 'https://t.me/bot?start=abc' });

        expect(screen.getByText('Telegram')).toBeInTheDocument();
    });
});
