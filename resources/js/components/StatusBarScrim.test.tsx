import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StatusBarScrim from './StatusBarScrim';

describe('StatusBarScrim', () => {
    it('paints the safe-area strip in the ground colour', () => {
        render(<StatusBarScrim />);
        expect(screen.getByTestId('status-bar-scrim')).toHaveClass(
            'fixed',
            'top-0',
            'h-[env(safe-area-inset-top)]',
            'bg-background',
        );
    });

    it('stays out of the accessibility tree and the hit test', () => {
        render(<StatusBarScrim />);
        const scrim = screen.getByTestId('status-bar-scrim');
        expect(scrim).toHaveAttribute('aria-hidden', 'true');
        expect(scrim).toHaveClass('pointer-events-none');
    });
});
