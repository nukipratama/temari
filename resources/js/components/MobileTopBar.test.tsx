import { act, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MobileTopBar from './MobileTopBar';
import { makeUser, setMockPage } from '@/test/setup';

describe('MobileTopBar', () => {
    it('renders the brand mark link to home', () => {
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Beranda')).toHaveAttribute('href', '/');
    });

    it('shows the user menu when a user is signed in', () => {
        setMockPage({ auth: { user: makeUser({ name: 'Ada Lovelace' }) } });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Buka menu Ada Lovelace')).toBeInTheDocument();
    });

    it('omits the user menu when there is no signed-in user', () => {
        setMockPage({ auth: { user: null } });
        render(<MobileTopBar />);
        expect(screen.queryByLabelText(/Buka menu/)).not.toBeInTheDocument();
    });

    it('renders the Strava sync badge in its disconnected state by default', () => {
        setMockPage({ auth: { user: null }, stravaSync: null });
        render(<MobileTopBar />);
        expect(screen.getByLabelText('Strava belum nyambung')).toBeInTheDocument();
    });

    // Installed as a PWA the page runs edge-to-edge, so this bar has to pad
    // itself past the notch or content slides under the status bar.
    it('pads the top by the safe-area inset so content clears the notch', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('pt-[max(0.75rem,env(safe-area-inset-top))]');
    });

    it('sticks to the top so content scrolls underneath it', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('sticky', 'top-0');
    });

    it('hides the hairline at rest and shows it once scrolled', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('border-transparent');

        act(() => {
            window.scrollY = 120;
            window.dispatchEvent(new Event('scroll'));
        });

        expect(container.querySelector('header')).toHaveClass('border-white/10');
        window.scrollY = 0;
    });

    // The bar is what sits under the forced-white iOS status glyphs once
    // `black-translucent` is on (app.blade.php). A cream bar leaves the clock
    // unreadable, so the dark ground is load-bearing, not decorative.
    it('keeps a dark ground so the white status glyphs stay legible', () => {
        const { container } = render(<MobileTopBar />);
        expect(container.querySelector('header')).toHaveClass('bg-sky/85');
    });
});
