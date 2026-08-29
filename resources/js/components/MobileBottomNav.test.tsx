import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import MobileBottomNav from './MobileBottomNav';

describe('MobileBottomNav', () => {
    it('renders all four primary tabs with their labels', () => {
        render(<MobileBottomNav />);
        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(screen.getByText('History')).toBeInTheDocument();
    });

    it('marks the tab matching the current url as active', () => {
        setMockPage({}, '/history');
        render(<MobileBottomNav />);
        const link = screen.getByText('History').closest('a')!;
        expect(link).toHaveAttribute('aria-current', 'page');
        expect(screen.getByText('Today').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('links each tab to its target path', () => {
        render(<MobileBottomNav />);
        expect(screen.getByText('Today').closest('a')).toHaveAttribute(
            'href',
            '/',
        );
        expect(screen.getByText('Plan').closest('a')).toHaveAttribute(
            'href',
            '/plan',
        );
        expect(screen.getByText('Trends').closest('a')).toHaveAttribute(
            'href',
            '/trends',
        );
        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'href',
            '/history',
        );
    });

    // The floating pill grows and gets a lime gradient fill for the active
    // tab (per the prototype's AppBottomNav); inactive tabs stay a plain
    // muted tone rather than the old bar's on-sky treatment.
    it('grows and tints the active tab, leaving inactive tabs muted', () => {
        setMockPage({}, '/history');
        render(<MobileBottomNav />);
        expect(screen.getByText('History').closest('a')).toHaveClass(
            'text-icon-accent',
            'grow-[1.6]',
        );
        expect(screen.getByText('Today').closest('a')).toHaveClass(
            'text-text-3',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    it('scrolls to top instead of navigating when the active tab is tapped', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        setMockPage({}, '/history');
        render(<MobileBottomNav />);

        const link = screen.getByText('History').closest('a')!;
        const event = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('leaves an inactive tab to navigate normally', () => {
        const scrollTo = vi.fn();
        vi.stubGlobal('scrollTo', scrollTo);
        setMockPage({}, '/history');
        render(<MobileBottomNav />);

        const link = screen.getByText('Today').closest('a')!;
        const event = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
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
        setMockPage({}, '/history');
        render(<MobileBottomNav />);

        screen
            .getByText('History')
            .closest('a')!
            .dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );

        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });
});
