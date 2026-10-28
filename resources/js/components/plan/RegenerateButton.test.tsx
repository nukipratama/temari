import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { defined } from '@/test/defined';

import RegenerateButton from './RegenerateButton';

beforeEach(() => {
    vi.mocked(router.post).mockClear();
});

async function confirmRegenerate() {
    fireEvent.click(screen.getByRole('button', { name: 'regenerate' }));
    fireEvent.click(await screen.findByRole('button', { name: 'replan' }));
}

describe('RegenerateButton', () => {
    it('asks first and posts nothing until the replan is confirmed', async () => {
        render(<RegenerateButton cooldownSeconds={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'regenerate' }));

        expect(
            await screen.findByRole('dialog', {
                name: 'replan the weeks ahead?',
            }),
        ).toBeInTheDocument();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('posts nothing when the confirm is dismissed', async () => {
        render(<RegenerateButton cooldownSeconds={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'regenerate' }));
        fireEvent.click(await screen.findByRole('button', { name: 'not now' }));

        expect(router.post).not.toHaveBeenCalled();
    });

    it('posts the regenerate request once, in place, on confirm', async () => {
        render(<RegenerateButton cooldownSeconds={null} />);

        await confirmRegenerate();

        expect(router.post).toHaveBeenCalledOnce();
        expect(router.post).toHaveBeenCalledWith(
            '/plan/regenerate',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('reads as replanning and disables while the request runs', async () => {
        render(<RegenerateButton cooldownSeconds={null} />);
        await confirmRegenerate();
        const { onStart, onFinish } = defined(
            vi.mocked(router.post).mock.calls[0],
        )[2] as { onStart: () => void; onFinish: () => void };

        act(() => onStart());
        expect(
            screen.getByRole('button', { name: 'replanning' }),
        ).toBeDisabled();

        act(() => onFinish());
        expect(
            screen.getByRole('button', { name: 'regenerate' }),
        ).toBeEnabled();
    });

    it('counts the cooldown down and frees the button at zero', () => {
        vi.useFakeTimers();
        try {
            render(<RegenerateButton cooldownSeconds={2} />);
            expect(
                screen.getByRole('button', {
                    name: 'regenerate, next in 0:02',
                }),
            ).toBeDisabled();

            act(() => {
                vi.advanceTimersByTime(2000);
            });

            expect(
                screen.getByRole('button', { name: 'regenerate' }),
            ).toBeEnabled();
        } finally {
            vi.useRealTimers();
        }
    });
});
