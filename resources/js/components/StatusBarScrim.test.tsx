import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatusBarScrim from './StatusBarScrim';

describe('StatusBarScrim', () => {
    it('is decorative and never intercepts taps', () => {
        render(<StatusBarScrim />);
        const scrim = screen.getByTestId('status-bar-scrim');
        expect(scrim).toHaveAttribute('aria-hidden');
        expect(scrim.className).toContain('pointer-events-none');
    });

    // The whole point: it has to outrank the modals, which sit at z-50/51 and
    // would otherwise put their own scrim under the forced-white status glyphs.
    it('sits above the modal layer', () => {
        render(<StatusBarScrim />);
        expect(screen.getByTestId('status-bar-scrim').className).toContain('z-[70]');
    });

    // Matching MobileTopBar's ground is what keeps the strip from reading as the
    // dark band this change exists to remove.
    it('uses the same sky ground as the top bar', () => {
        render(<StatusBarScrim />);
        expect(screen.getByTestId('status-bar-scrim').className).toContain('bg-sky');
    });

    it('takes its height from the safe-area inset, so it collapses off-device', () => {
        render(<StatusBarScrim />);
        expect(screen.getByTestId('status-bar-scrim')).toHaveClass('h-[env(safe-area-inset-top)]');
    });
});
