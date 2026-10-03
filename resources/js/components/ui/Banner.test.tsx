import { fireEvent, render, screen } from '@testing-library/react';
import { Moon } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';

import Banner, { type BannerTone } from './Banner';

describe('Banner', () => {
    it('centres the message in the shell column under the shared gutter', () => {
        const { container } = render(<Banner icon={Moon}>resting</Banner>);
        const gutter = container.firstElementChild as HTMLElement;
        const frame = gutter.firstElementChild as HTMLElement;

        expect(gutter).toHaveClass('px-4', 'pt-4', 'min-[900px]:px-6');
        expect(frame).toHaveClass(
            'mx-auto',
            'flex',
            'min-[900px]:max-w-column',
            'min-[1280px]:max-w-column-wide',
            'rounded-lg',
            'border',
            'px-4',
            'py-3',
        );
        expect(screen.getByText('resting')).toHaveClass('flex-1', 'text-sm');
    });

    it.each([
        ['neutral', 'bg-muted', 'text-text-3'],
        ['success', 'bg-leaf/[0.08]', 'text-leaf-ink'],
        ['error', 'bg-ember/[0.08]', 'text-ember-ink'],
    ] satisfies [BannerTone, string, string][])(
        'paints the %s tone on its frame and glyph',
        (tone, frame, glyph) => {
            const { container } = render(
                <Banner tone={tone} icon={Moon}>
                    x
                </Banner>,
            );
            const box = container.firstElementChild
                ?.firstElementChild as HTMLElement;

            expect(box).toHaveClass(frame);
            expect(box.firstElementChild).toHaveClass(glyph);
        },
    );

    it('announces an error as an alert and other tones only when asked', () => {
        const { rerender } = render(
            <Banner tone="error" icon={Moon}>
                x
            </Banner>,
        );
        expect(screen.getByRole('alert')).toBeInTheDocument();

        rerender(
            <Banner icon={Moon} role="status">
                x
            </Banner>,
        );
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('draws one close button that calls onDismiss', () => {
        const onDismiss = vi.fn();
        render(
            <Banner icon={Moon} onDismiss={onDismiss}>
                x
            </Banner>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Close' }));

        expect(onDismiss).toHaveBeenCalledOnce();
    });

    it('draws no close button without onDismiss', () => {
        render(<Banner icon={Moon}>x</Banner>);

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('places an action between the message and the close button', () => {
        render(
            <Banner
                icon={Moon}
                action={<a href="/x">reconnect</a>}
                onDismiss={() => {}}
            >
                x
            </Banner>,
        );
        const link = screen.getByRole('link', { name: 'reconnect' });

        expect(link.previousElementSibling).toHaveTextContent('x');
        expect(link.nextElementSibling).toHaveAccessibleName('Close');
    });
});
