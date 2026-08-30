import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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

    it('offers no send while narration is not done', () => {
        render(
            <RecapCard
                mood="easy"
                analysis={analysis({ status: 'pending', content: null })}
                fallback="fallback copy"
                notification={{
                    url: '/recaps/weekly/7/send',
                    retryAfterSeconds: null,
                }}
            />,
        );

        expect(screen.queryByText('Send notification')).not.toBeInTheDocument();
    });

    it('force-sends the recap when a channel is wired and the button is clicked', () => {
        vi.mocked(router.post).mockReset();
        setMockPage({
            auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
            flash: {},
            demoLoginEnabled: false,
            stravaSync: { state: 'ready', last_synced_at: '2026-01-01' },
            telegramConnected: true,
        });

        render(
            <RecapCard
                mood="blazing"
                analysis={analysis()}
                fallback="fallback copy"
                notification={{
                    url: '/recaps/weekly/7/send',
                    retryAfterSeconds: null,
                }}
            />,
        );

        fireEvent.click(screen.getByText('Send notification'));
        expect(router.post).toHaveBeenCalledWith(
            '/recaps/weekly/7/send',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders no send affordance at all when notification is omitted', () => {
        render(
            <RecapCard
                mood="blazing"
                analysis={analysis()}
                fallback="fallback copy"
            />,
        );

        expect(screen.queryByText('Send notification')).not.toBeInTheDocument();
    });
});
