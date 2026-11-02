import { router } from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { showToast } from '@/hooks/useToast';

import QualityOverrideAction from './QualityOverrideAction';

vi.mock('@/hooks/useToast', () => ({ showToast: vi.fn() }));

const offered = {
    kind: 'readiness_ease',
    state: 'offered',
    label: "i'm ready",
    reason: 'hard sessions have landed close together, so quality can wait.',
} as const;

describe('QualityOverrideAction', () => {
    beforeEach(() => {
        vi.mocked(router.post).mockClear();
        vi.mocked(router.delete).mockClear();
        vi.mocked(showToast).mockClear();
    });

    it('asks with the plan’s own reason before overriding, and cancel sends nothing', async () => {
        render(<QualityOverrideAction sessionId={7} override={offered} />);

        fireEvent.click(screen.getByRole('button', { name: "i'm ready" }));
        const dialog = await screen.findByRole('dialog', { name: "i'm ready" });

        expect(dialog).toHaveTextContent(
            'hard sessions have landed close together, so quality can wait. the coach will remember this.',
        );
        fireEvent.click(within(dialog).getByRole('button', { name: 'cancel' }));
        expect(router.post).not.toHaveBeenCalled();
    });

    it('overrides the day on confirm', async () => {
        render(
            <QualityOverrideAction
                sessionId={7}
                override={{
                    ...offered,
                    kind: 'weekly_drop',
                    label: "bring back thursday's tempo",
                }}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: "bring back thursday's tempo" }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: "bring back thursday's tempo",
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'confirm' }),
        );

        expect(router.post).toHaveBeenCalledOnce();
        expect(vi.mocked(router.post).mock.calls[0]?.slice(0, 2)).toEqual([
            '/plan/sessions/7/override',
            {},
        ]);
    });

    it('says the athlete chose the day and eases it again while undoable', () => {
        render(
            <QualityOverrideAction
                sessionId={7}
                override={{
                    kind: 'readiness_ease',
                    state: 'taken',
                    note: 'you chose to run this one as written.',
                    undoable: true,
                }}
            />,
        );

        expect(
            screen.getByText('you chose to run this one as written.'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'ease it again' }));

        expect(vi.mocked(router.delete).mock.calls[0]?.[0]).toBe(
            '/plan/sessions/7/override',
        );
    });

    it('keeps only the note once a run has landed', () => {
        render(
            <QualityOverrideAction
                sessionId={7}
                override={{
                    kind: 'weekly_drop',
                    state: 'taken',
                    note: 'you chose to run this one as written.',
                    undoable: false,
                }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'ease it again' }),
        ).not.toBeInTheDocument();
    });

    it('toasts the server’s refusal', () => {
        vi.mocked(router.post).mockImplementationOnce(
            (_url, _data, options) => {
                options?.onError?.({
                    override: "this day can't be overridden.",
                });
            },
        );
        render(<QualityOverrideAction sessionId={7} override={offered} />);

        fireEvent.click(screen.getByRole('button', { name: "i'm ready" }));
        return screen
            .findByRole('dialog', { name: "i'm ready" })
            .then((dialog) => {
                fireEvent.click(
                    within(dialog).getByRole('button', { name: 'confirm' }),
                );
                expect(showToast).toHaveBeenCalledWith(
                    "this day can't be overridden.",
                );
            });
    });
});
