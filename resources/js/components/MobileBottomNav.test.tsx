import type { GlobalEvent } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { setMockPage } from '@/test/setup';

import MobileBottomNav from './MobileBottomNav';

function finishHandler() {
    const call = vi
        .mocked(router.on)
        .mock.calls.find(([name]) => name === 'finish');
    if (!call) {
        throw new Error('router.on was never called for "finish"');
    }
    return call[1] as (event: GlobalEvent<'finish'>) => void;
}

function fireFinish() {
    act(() => {
        finishHandler()({} as GlobalEvent<'finish'>);
    });
}

describe('MobileBottomNav', () => {
    beforeEach(() => {
        vi.mocked(router.on).mockClear();
    });

    it('renders all four primary tabs with their labels', () => {
        render(<MobileBottomNav />);
        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(screen.getByText('History')).toBeInTheDocument();
    });

    it('marks the tab for the current page component as active', () => {
        setMockPage({}, '/history', 'History');
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
        setMockPage({}, '/history', 'History');
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
        setMockPage({}, '/history', 'History');
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
        setMockPage({}, '/history', 'History');
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
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        screen
            .getByText('History')
            .closest('a')!
            .dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );

        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });

    it('lights the plan tab on Race, a sub-page of Plan', () => {
        setMockPage({}, '/race', 'Race');
        render(<MobileBottomNav />);
        expect(screen.getByText('Plan').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('renders nothing on a pushed screen', () => {
        setMockPage({}, '/inbox', 'Inbox');
        const { container } = render(<MobileBottomNav />);
        expect(container).toBeEmptyDOMElement();
    });

    it('lights the tapped tab before the server has answered', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        fireEvent.click(screen.getByText('Today').closest('a')!);

        expect(screen.getByText('Today').closest('a')).toHaveClass(
            'grow-[1.6]',
            'text-icon-accent',
        );
        expect(screen.getByText('History').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    // The highlight is optimistic; `aria-current` is not, so a screen reader is
    // never told it is on a page the app has not reached.
    it('leaves aria-current on the page actually being shown', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        fireEvent.click(screen.getByText('Today').closest('a')!);

        expect(screen.getByText('History').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('hands the highlight back to the current page when a visit never arrives', () => {
        setMockPage({}, '/history', 'History');
        render(<MobileBottomNav />);

        fireEvent.click(screen.getByText('Today').closest('a')!);
        fireFinish();

        expect(screen.getByText('History').closest('a')).toHaveClass(
            'grow-[1.6]',
        );
        expect(screen.getByText('Today').closest('a')).not.toHaveClass(
            'grow-[1.6]',
        );
    });

    it('keeps the pill clear of a landscape notch on both sides', () => {
        setMockPage({}, '/', 'Home');
        const { container } = render(<MobileBottomNav />);

        expect(container.firstElementChild).toHaveClass(
            'pl-[max(0.875rem,env(safe-area-inset-left))]',
            'pr-[max(0.875rem,env(safe-area-inset-right))]',
        );
    });

    it('centres the pill on the content column rather than spanning the viewport', () => {
        setMockPage({}, '/', 'Home');
        render(<MobileBottomNav />);
        expect(screen.getByRole('navigation')).toHaveClass(
            'mx-auto',
            'max-w-column',
        );
    });

    it('tracks the content column at its wide step too', () => {
        setMockPage({}, '/', 'Home');
        render(<MobileBottomNav />);
        // A pill narrower than the content above it reads as misaligned; P32's
        // objection was to a full-bleed track, which 1040 still is not.
        expect(screen.getByRole('navigation')).toHaveClass(
            'min-[1280px]:max-w-column-wide',
        );
    });
});
