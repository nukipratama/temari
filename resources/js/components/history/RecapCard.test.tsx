import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import RecapCard from './RecapCard';

function analysis(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'Consistent week.',
        type: 'weekly_recap',
        subject_type: 'weekly_snapshot',
        subject_id: 7,
        discriminator: null,
        ...overrides,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
        flash: {},
        demoLoginEnabled: false,
        stravaSync: { state: 'ready', last_synced_at: '2026-01-01' },
    });
});

describe('RecapCard', () => {
    it('carries its own watermark, posed to its mood', () => {
        const { container } = render(
            <RecapCard mood="gassed" analysis={analysis()} />,
        );
        const mascot = container.querySelector('svg[data-mascot]');

        expect(mascot?.getAttribute('data-mascot')).toBe('gassed');
        expect(mascot?.getAttribute('width')).toBe('200');
        expect(mascot?.getAttribute('class')).toContain('-bottom-15');
    });

    it('thinks while its recap is being written', () => {
        const { container } = render(
            <RecapCard
                mood="easy"
                analysis={analysis({ status: 'processing', content: null })}
            />,
        );

        expect(
            container
                .querySelector('svg[data-mascot]')
                ?.getAttribute('data-mascot'),
        ).toBe('thinking');
    });

    it('falls back to the neutral pose when the period has no mood', () => {
        const { container } = render(
            <RecapCard mood={null} analysis={analysis()} />,
        );

        expect(
            container
                .querySelector('svg[data-mascot]')
                ?.getAttribute('data-mascot'),
        ).toBe('neutral');
    });

    it('renders the done narration and any chips passed in', () => {
        render(
            <RecapCard
                mood="blazing"
                analysis={analysis()}
                fallback="fallback copy"
                chips={<span>fatigue moderate</span>}
            />,
        );

        expect(screen.getByText('Consistent week.')).toBeInTheDocument();
        expect(screen.getByText('fatigue moderate')).toBeInTheDocument();
        expect(screen.queryByText('fallback copy')).not.toBeInTheDocument();
    });

    it('shows the fallback copy while the narration is not done', () => {
        render(
            <RecapCard
                mood="easy"
                analysis={analysis({ status: 'pending', content: null })}
                fallback="You ran 3x this week."
            />,
        );

        expect(screen.getByText('You ran 3x this week.')).toBeInTheDocument();
    });

    it('puts the chips on one wrapping row above the narration', () => {
        render(
            <RecapCard
                mood="blazing"
                analysis={analysis()}
                chips={<span>fatigue moderate</span>}
            />,
        );

        const secondary = screen.getByTestId('recap-secondary');
        expect(secondary.className).toContain('flex-wrap');
        expect(
            secondary.compareDocumentPosition(
                screen.getByText('Consistent week.'),
            ) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('renders no secondary row when there is nothing honest to put in it', () => {
        render(
            <RecapCard
                mood="blazing"
                analysis={analysis({ type: 'monthly_recap' })}
            />,
        );

        expect(screen.queryByTestId('recap-secondary')).not.toBeInTheDocument();
    });
});
