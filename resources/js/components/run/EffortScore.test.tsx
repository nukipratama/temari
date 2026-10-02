import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { setMockPage } from '@/test/setup';

import { EffortChip, EffortPicker } from './EffortScore';

function renderPicker(saved: number | null = null, onClose = vi.fn()) {
    return render(
        <EffortPicker activityId={42} saved={saved} onClose={onClose} />,
    );
}

const VOICE_LINE = "forget the watch. how'd that one feel?";

function slider(): HTMLElement {
    return screen.getByRole('slider', { name: VOICE_LINE });
}

function mascot(container: HTMLElement): Element {
    const svg = container.querySelector('[data-mascot]');
    expect(svg).not.toBeNull();

    return svg as Element;
}

describe('EffortPicker', () => {
    beforeEach(() => {
        vi.mocked(router.patch).mockReset();
        vi.mocked(router.delete).mockReset();
    });

    it('sits on a fixed-dark sky panel on both grounds', () => {
        const { container } = renderPicker();

        expect(container.firstElementChild).toHaveClass(
            'bg-sky',
            'rounded-panel',
        );
        expect(container.firstElementChild).toHaveAttribute(
            'data-theme',
            'dark',
        );
    });

    it('opens with a gut-check eyebrow and a temari voice line', () => {
        renderPicker();

        expect(screen.getByText('gut check')).toHaveClass(
            'text-label-small',
            'text-ink-on-sky',
        );
        expect(screen.getByText(VOICE_LINE)).toHaveClass(
            'font-serif',
            'italic',
            'text-cream',
        );
    });

    it('holds the mascot in a fixed 44px column beside the text', () => {
        const { container } = renderPicker();

        const svg = mascot(container);
        expect(svg).toHaveAttribute('width', '44');
        expect(svg.parentElement).toHaveClass(
            'grid',
            'grid-cols-[44px_minmax(0,1fr)]',
        );
        expect(svg.parentElement?.firstElementChild).toBe(svg);
        expect(svg.nextElementSibling).toContainElement(
            screen.getByText('gut check'),
        );
    });

    it('poses the mascot from the slider, neutral until it is touched', () => {
        const { container } = renderPicker();

        expect(mascot(container)).toHaveAttribute('data-mascot', 'neutral');
        fireEvent.change(slider(), { target: { value: '3' } });
        expect(mascot(container)).toHaveAttribute('data-mascot', 'easy');
        fireEvent.change(slider(), { target: { value: '7' } });
        expect(mascot(container)).toHaveAttribute('data-mascot', 'gassed');
    });

    it('starts unrated: an en dash, a muted thumb, and save disabled until the slider moves', () => {
        renderPicker();

        expect(slider()).toHaveAttribute('aria-valuetext', 'not rated yet');
        expect(slider()).toHaveAttribute('data-rated', 'false');
        expect(screen.getByText('–')).toBeInTheDocument();
        expect(screen.getByText('drag to rate')).toHaveClass('text-ink-on-sky');
        expect(screen.getByRole('button', { name: 'save' })).toBeDisabled();
    });

    it('puts save on the score row, with no divider below the slider', () => {
        const { container } = renderPicker();

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
        const { container } = renderPicker();

        const fills = ['bg-leaf', 'bg-citrus', 'bg-ember'].map(
            (fill) => container.querySelectorAll(`.${fill}`).length,
        );
        expect(fills).toEqual([4, 2, 4]);
        expect(screen.getByText('easy')).toHaveClass('col-span-4');
        expect(screen.getByText('steady')).toHaveClass('col-span-2');
        expect(screen.getByText('hard')).toHaveClass('col-span-4');
    });

    it('names the effort in its colour as the slider moves, and saves the chosen score', () => {
        renderPicker();

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
        renderPicker();

        fireEvent.change(slider(), { target: { value: '4' } });
        expect(screen.getByText('somewhat hard')).toHaveClass('text-leaf-ink');
        fireEvent.change(slider(), { target: { value: '5' } });
        expect(screen.getByText('hard', { selector: 'p span' })).toHaveClass(
            'text-citrus-ink',
        );
    });

    it('reopens a saved score, where clear removes it and cancel closes', () => {
        const onClose = vi.fn();
        renderPicker(3, onClose);

        expect(slider()).toHaveAttribute('aria-valuetext', '3 of 10, moderate');

        fireEvent.click(screen.getByRole('button', { name: 'clear' }));
        expect(router.delete).toHaveBeenCalledWith(
            '/activities/42/effort',
            expect.objectContaining({ preserveScroll: true }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'cancel' }));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('offers no clear on a run that has no score yet', () => {
        renderPicker();

        expect(
            screen.queryByRole('button', { name: 'clear' }),
        ).not.toBeInTheDocument();
    });

    it('closes once a save lands', () => {
        const onClose = vi.fn();
        renderPicker(3, onClose);
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

        expect(onClose).toHaveBeenCalledOnce();
    });

    it('shows a validation error', () => {
        setMockPage({
            errors: { score: 'The score field must be between 1 and 10.' },
        });
        renderPicker();

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
