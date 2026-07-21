import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MobileBottomNav from './MobileBottomNav';
import { setMockPage } from '@/test/setup';

describe('MobileBottomNav', () => {
    it('renders all four primary tabs with their labels', () => {
        render(<MobileBottomNav />);
        expect(screen.getByText('Hari Ini')).toBeInTheDocument();
        expect(screen.getByText('Koleksi')).toBeInTheDocument();
        expect(screen.getByText('Riwayat')).toBeInTheDocument();
        expect(screen.getByText('Aku')).toBeInTheDocument();
    });

    it('marks the tab matching the current url as active', () => {
        setMockPage({}, '/kartu');
        render(<MobileBottomNav />);
        const link = screen.getByText('Koleksi').closest('a')!;
        expect(link).toHaveAttribute('aria-current', 'page');
        expect(screen.getByText('Hari Ini').closest('a')).not.toHaveAttribute('aria-current');
    });

    it('links each tab to its target path', () => {
        render(<MobileBottomNav />);
        expect(screen.getByText('Riwayat').closest('a')).toHaveAttribute('href', '/aktivitas');
        expect(screen.getByText('Aku').closest('a')).toHaveAttribute('href', '/profil');
    });

    // ink-on-sky is the design system's muted tone for dark sky panels; the
    // old text-cream/55 it replaced sat at roughly 2.2:1 against the bar.
    it('tints inactive tabs with the readable on-sky muted tone', () => {
        setMockPage({}, '/kartu');
        render(<MobileBottomNav />);
        expect(screen.getByText('Koleksi').closest('a')).toHaveClass('text-horizon');
        expect(screen.getByText('Aku').closest('a')).toHaveClass('text-ink-on-sky');
    });

    // Native tab bars scroll to top when you tap the tab you are already on.
    // Falling through to the Link would instead issue a full Inertia visit —
    // a round trip, a remount and a scroll reset — for a page you never left.
    it('scrolls to top instead of navigating when the active tab is tapped', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        setMockPage({}, '/kartu');
        render(<MobileBottomNav />);

        const link = screen.getByText('Koleksi').closest('a')!;
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('leaves an inactive tab to navigate normally', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        setMockPage({}, '/kartu');
        render(<MobileBottomNav />);

        const link = screen.getByText('Aku').closest('a')!;
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(scrollTo).not.toHaveBeenCalled();
    });

    it('jumps without animating when the user asks for reduced motion', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        vi.stubGlobal(
            'matchMedia',
            vi.fn((query: string) => ({
                matches: query.includes('prefers-reduced-motion'),
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            })),
        );
        setMockPage({}, '/kartu');
        render(<MobileBottomNav />);

        screen.getByText('Koleksi').closest('a')!.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );

        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });
});
