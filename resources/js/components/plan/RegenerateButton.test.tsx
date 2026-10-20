import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { defined } from '@/test/defined';

import RegenerateButton from './RegenerateButton';

describe('RegenerateButton', () => {
    it('posts the regenerate request in place', () => {
        render(<RegenerateButton cooldownSeconds={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'regenerate' }));

        expect(router.post).toHaveBeenCalledWith(
            '/plan/regenerate',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('reads as replanning and disables while the request runs', () => {
        render(<RegenerateButton cooldownSeconds={null} />);
        fireEvent.click(screen.getByRole('button', { name: 'regenerate' }));
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
