import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { AttentionBlock } from '@/pages/Narration/types';

import { formMock, setMockPage } from '@/test/setup';

import AttentionTab from './AttentionTab';

function block(overrides: Partial<AttentionBlock> = {}): AttentionBlock {
    return {
        id: 1,
        kind: 'weekly_recap',
        status: 'failed',
        attempts: 1,
        error: 'Azure down',
        at: '2026-09-10T08:00:00Z',
        ...overrides,
    };
}

function renderTab(
    overrides: Partial<Parameters<typeof AttentionTab>[0]> = {},
) {
    return render(
        <AttentionTab
            athleteId={7}
            currency="USD"
            failed={[block()]}
            deadLettered={[]}
            stuck={[]}
            audit={[]}
            override={null}
            {...overrides}
        />,
    );
}

beforeEach(() => {
    vi.mocked(router.post).mockClear();
});

describe('AttentionTab', () => {
    it('lists the failed blocks and confirms before retrying them', () => {
        renderTab();

        expect(screen.getByText('Azure down')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: /retry all failed/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'confirm' }));

        expect(formMock.post).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7/retry-failed',
            { preserveScroll: true },
        );
    });

    it('offers a re-arm and a resync of their own', () => {
        renderTab({
            deadLettered: [block({ id: 2, attempts: 3 })],
            stuck: [block({ id: 3, status: 'processing' })],
        });

        expect(
            screen.getByRole('button', { name: /re-arm dead-lettered/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /resync strava/i }),
        ).toBeInTheDocument();
    });

    it('confirms the ceiling override before posting it', () => {
        renderTab();

        fireEvent.change(screen.getByLabelText(/dollars for today/i), {
            target: { value: '3.5' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'set for today' }));

        expect(screen.getByText(/spend/)).toBeInTheDocument();
        expect(screen.getByText(/\$3\.50/)).toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'confirm' }));

        expect(router.post).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7/ceiling',
            { ceiling: '3.5' },
            { preserveScroll: true },
        );
    });

    it('drops the confirm step on cancel', () => {
        renderTab();

        fireEvent.click(screen.getByRole('button', { name: 'set for today' }));
        fireEvent.click(screen.getByRole('button', { name: 'cancel' }));

        expect(
            screen.getByRole('button', { name: 'set for today' }),
        ).toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('shows an active override with a clear action', () => {
        renderTab({
            override: { value: 4, expires_at: '2026-09-11T00:00:00Z' },
        });

        expect(screen.getByText(/active at \$4\.00/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /clear override/i }),
        ).toBeInTheDocument();
    });

    it('surfaces a rejected ceiling', () => {
        setMockPage({ errors: { ceiling: 'must be at least 0.' } });
        renderTab();

        expect(screen.getByText('must be at least 0.')).toBeInTheDocument();
    });

    it('lists the recent operator actions, or says there are none', () => {
        renderTab();
        expect(
            screen.getByText(
                'nothing has been done to this athlete from devtools yet.',
            ),
        ).toBeInTheDocument();

        renderTab({
            audit: [
                {
                    actor: 'nuki',
                    action: 'narration.resync',
                    payload: { blocks: 2 },
                    at: '2026-09-10T08:00:00Z',
                },
            ],
        });

        expect(screen.getByText('narration.resync')).toBeInTheDocument();
        expect(screen.getByText('{"blocks":2}')).toBeInTheDocument();
    });
});
