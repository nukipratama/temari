import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { showToast, TOAST_DISMISS_MS } from '@/hooks/useToast';

import Toaster, { ToastMessage } from './Toast';

describe('Toaster', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        act(() => {
            vi.runAllTimers();
        });
        vi.useRealTimers();
    });

    it('keeps an empty polite live region mounted before any toast', () => {
        render(<Toaster aboveNav />);

        const region = screen.getByRole('status');
        expect(region).toHaveAttribute('aria-live', 'polite');
        expect(region).toBeEmptyDOMElement();
    });

    it('announces a toast in the live region and drops it after the dismiss delay', () => {
        render(<Toaster aboveNav />);

        act(() => showToast('moved to Thu'));
        expect(screen.getByRole('status')).toHaveTextContent('moved to Thu');

        act(() => {
            vi.advanceTimersByTime(TOAST_DISMISS_MS);
        });
        expect(screen.getByRole('status')).toBeEmptyDOMElement();
    });

    it('sits above the nav pill and its safe-area offset when the nav shows', () => {
        render(<Toaster aboveNav />);

        expect(screen.getByRole('status')).toHaveClass(
            'bottom-[calc(max(0.875rem,calc(env(safe-area-inset-bottom)+0.25rem))+4rem)]',
        );
    });

    it('drops to the safe area when the page has no nav', () => {
        render(<Toaster aboveNav={false} />);

        expect(screen.getByRole('status')).toHaveClass(
            'bottom-[max(0.875rem,calc(env(safe-area-inset-bottom)+0.25rem))]',
        );
    });
});

describe('ToastMessage', () => {
    it('draws on the ground-reactive popover tokens', () => {
        render(<ToastMessage>skipped</ToastMessage>);

        expect(screen.getByText('skipped')).toHaveClass(
            'bg-popover',
            'text-popover-foreground',
            'shadow-e2',
        );
    });

    it('rises in on the shared reveal entrance, which drops to a fade under reduced motion', () => {
        render(<ToastMessage>skipped</ToastMessage>);

        expect(screen.getByText('skipped')).toHaveClass('reveal');
    });
});
