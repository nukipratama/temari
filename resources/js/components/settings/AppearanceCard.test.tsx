import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import AppearanceCard from './AppearanceCard';

function mockMatchMedia(matches: boolean) {
    window.matchMedia = ((query: string) => ({
        matches,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
}

describe('AppearanceCard', () => {
    beforeEach(() => {
        mockMatchMedia(false);
        localStorage.clear();
        delete document.documentElement.dataset.theme;
        document.documentElement.style.colorScheme = '';
    });

    it('renders all three options with their icons', () => {
        render(<AppearanceCard />);

        expect(
            screen.getByRole('button', { name: 'light' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'dark' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'system' }),
        ).toBeInTheDocument();
        expect(
            screen
                .getByRole('button', { name: 'light' })
                .querySelector('[data-icon="mdi:white-balance-sunny"]'),
        ).toBeInTheDocument();
    });

    it('shows the stored preference as pressed on mount', () => {
        localStorage.setItem('temari-theme', 'light');
        render(<AppearanceCard />);

        expect(screen.getByRole('button', { name: 'light' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: 'dark' })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });

    it('defaults to dark pressed when nothing is stored', () => {
        render(<AppearanceCard />);

        expect(screen.getByRole('button', { name: 'dark' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('switches the ground live and persists the choice when a different option is tapped', () => {
        render(<AppearanceCard />);

        fireEvent.click(screen.getByRole('button', { name: 'light' }));

        expect(document.documentElement.dataset.theme).toBe('light');
        expect(document.documentElement.style.colorScheme).toBe('light');
        expect(localStorage.getItem('temari-theme')).toBe('light');
        expect(screen.getByRole('button', { name: 'light' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('resolves system against the current OS preference', () => {
        mockMatchMedia(true);
        render(<AppearanceCard />);

        fireEvent.click(screen.getByRole('button', { name: 'system' }));

        expect(localStorage.getItem('temari-theme')).toBe('system');
        expect(document.documentElement.dataset.theme).toBe('dark');
    });
});
