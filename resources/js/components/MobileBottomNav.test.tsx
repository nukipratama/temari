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
});
