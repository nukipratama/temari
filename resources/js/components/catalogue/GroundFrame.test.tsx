import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Overlay, { OverlayTitle } from '@/components/ui/Overlay';

import GroundFrame from './GroundFrame';

describe('GroundFrame', () => {
    it('draws the example twice, the second under the dark ground', () => {
        const { container } = render(
            <GroundFrame>
                <span>example</span>
            </GroundFrame>,
        );

        const frames = container.querySelectorAll<HTMLElement>('[data-ground]');
        expect([...frames].map((frame) => frame.dataset.ground)).toEqual([
            'light',
            'dark',
        ]);
        expect(frames[0]).not.toHaveAttribute('data-theme');
        expect(frames[1]).toHaveAttribute('data-theme', 'dark');
        expect(screen.getAllByText('example')).toHaveLength(2);
    });

    it('hosts an overlay inside each frame, so the dark one opens dark', async () => {
        const { container } = render(
            <GroundFrame overlay>
                <Overlay open onOpenChange={() => undefined}>
                    <OverlayTitle>sheet</OverlayTitle>
                </Overlay>
            </GroundFrame>,
        );

        const dialogs = await screen.findAllByRole('dialog', {
            name: 'sheet',
            hidden: true,
        });
        const dark = container.querySelector('[data-ground="dark"]');
        expect(dialogs.some((dialog) => dark?.contains(dialog))).toBe(true);
        expect(dark?.lastElementChild).toHaveClass('transform-gpu');
    });
});
