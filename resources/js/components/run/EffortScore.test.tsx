import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { setMockPage } from '@/test/setup';

import EffortScore, { EffortChip } from './EffortScore';

const PROMPT = {
    activity_id: 42,
    score: null,
    name: 'Treadmill',
    start_date_local: '2026-10-05T06:00:00',
};

function slider(): HTMLElement {
    return screen.getByRole('slider', { name: 'how hard did it feel' });
}

describe('EffortScore', () => {
    beforeEach(() => {
        vi.mocked(router.patch).mockReset();
        vi.mocked(router.delete).mockReset();
    });

    it('starts unrated: an en dash, a muted thumb, and save disabled until the slider moves', () => {
        render(<EffortScore prompt={PROMPT} />);

        expect(slider()).toHaveAttribute('aria-valuetext', 'not rated yet');
        expect(slider()).toHaveAttribute('data-rated', 'false');
        expect(screen.getByText('–')).toBeInTheDocument();
        expect(screen.getByText('drag to rate')).toHaveClass('text-text-3');
        expect(screen.getByRole('button', { name: 'save' })).toBeDisabled();
    });

    it('puts save on the score row, with no divider below the slider', () => {
        const { container } = render(<EffortScore prompt={PROMPT} />);

        const row = screen
            .getByText('drag to rate')
            .closest('[data-score-row]');
        expect(row).not.toBeNull();
        expect(row).toContainElement(
            screen.getByRole('button', { name: 'save' }),
        );
        expect(container.querySelector('.border-dashed')).toBeNull();
    });

    it('draws ten segments in the effort colours, 4 easy, 2 steady, 4 hard', () => {
        const { container } = render(<EffortScore prompt={PROMPT} />);

        const fills = ['bg-leaf', 'bg-citrus', 'bg-ember'].map(
            (fill) => container.querySelectorAll(`.${fill}`).length,
        );
        expect(fills).toEqual([4, 2, 4]);
        expect(screen.getByText('easy')).toHaveClass('col-span-4');
        expect(screen.getByText('steady')).toHaveClass('col-span-2');
        expect(screen.getByText('hard')).toHaveClass('col-span-4');
    });

    it('names the effort in its colour as the slider moves, and saves the chosen score', () => {
        render(<EffortScore prompt={PROMPT} runName="Treadmill" />);

        expect(screen.getByText('Treadmill')).toBeInTheDocument();
        fireEvent.change(slider(), { target: { value: '7' } });

        expect(slider()).toHaveAttribute(
            'aria-valuetext',
            '7 of 10, very hard',
        );
        expect(slider()).toHaveAttribute('data-rated', 'true');
        expect(screen.getByText('very hard')).toHaveClass('text-ember-ink');
        expect(screen.getByText('7')).toHaveClass('text-stat');

        fireEvent.click(screen.getByRole('button', { name: 'save' }));
        expect(router.patch).toHaveBeenCalledWith(
            '/activities/42/effort',
            { score: 7 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('colours an easy and a steady score with their own ink', () => {
        render(<EffortScore prompt={PROMPT} />);

        fireEvent.change(slider(), { target: { value: '4' } });
        expect(screen.getByText('somewhat hard')).toHaveClass('text-leaf-ink');
        fireEvent.change(slider(), { target: { value: '5' } });
        expect(screen.getByText('hard', { selector: 'p span' })).toHaveClass(
            'text-citrus-ink',
        );
    });

    it('collapses a saved score to a chip that reopens the picker, where clear removes it', () => {
        render(<EffortScore prompt={{ ...PROMPT, score: 3 }} />);

        expect(screen.getByText('3/10')).toHaveClass('font-mono');
        expect(screen.queryByRole('slider')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'clear' }),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: /change effort score/ }),
        );
        expect(slider()).toHaveAttribute('aria-valuetext', '3 of 10, moderate');

        fireEvent.click(screen.getByRole('button', { name: 'clear' }));
        expect(router.delete).toHaveBeenCalledWith(
            '/activities/42/effort',
            expect.objectContaining({ preserveScroll: true }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'cancel' }));
        expect(screen.queryByRole('slider')).not.toBeInTheDocument();
        expect(screen.getByText('3/10')).toBeInTheDocument();
    });

    it('offers no clear on a run that has no score yet', () => {
        render(<EffortScore prompt={PROMPT} />);

        expect(
            screen.queryByRole('button', { name: 'clear' }),
        ).not.toBeInTheDocument();
    });

    it('returns to the chip once a save lands', () => {
        render(<EffortScore prompt={{ ...PROMPT, score: 3 }} />);
        fireEvent.click(
            screen.getByRole('button', { name: /change effort score/ }),
        );
        fireEvent.change(slider(), { target: { value: '4' } });
        fireEvent.click(screen.getByRole('button', { name: 'save' }));

        const options = vi.mocked(router.patch).mock.calls[0][2] as {
            onStart: () => void;
            onFinish: () => void;
            onSuccess: () => void;
        };
        act(() => {
            options.onStart();
            options.onSuccess();
            options.onFinish();
        });

        expect(screen.queryByRole('slider')).not.toBeInTheDocument();
    });

    it('shows a validation error', () => {
        setMockPage({
            errors: { score: 'The score field must be between 1 and 10.' },
        });
        render(<EffortScore prompt={PROMPT} />);

        expect(screen.getByRole('alert')).toHaveTextContent(/between 1 and 10/);
    });
});

describe('EffortChip', () => {
    it('prints the score in its band ink on the band tint', () => {
        render(<EffortChip score={7} />);

        const chip = screen.getByText('7/10').parentElement;
        expect(chip).toHaveTextContent('7/10 · very hard');
        expect(chip).toHaveClass('bg-ember/15', 'text-ember-ink');
    });

    it('tints an easy and a steady score with their own band', () => {
        const { rerender } = render(<EffortChip score={4} />);
        expect(screen.getByText('4/10').parentElement).toHaveClass(
            'bg-leaf/15',
            'text-leaf-ink',
        );
        rerender(<EffortChip score={6} />);
        expect(screen.getByText('6/10').parentElement).toHaveClass(
            'bg-citrus/15',
            'text-citrus-ink',
        );
    });
});
