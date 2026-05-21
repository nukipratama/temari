import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import DetailTeknisCollapsible from './DetailTeknisCollapsible';

const STATS = [
    { label: 'TRIMP', value: '240' },
    { label: 'CTL', value: '28.4' },
] as const;

describe('DetailTeknisCollapsible', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    afterEach(() => {
        window.localStorage.clear();
    });

    it('is collapsed by default', () => {
        render(<DetailTeknisCollapsible storageKey="2026-05-10" stats={[...STATS]} />);
        expect(screen.queryByText('240')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { expanded: false })).toBeInTheDocument();
    });

    it('expands on click and reveals stats', () => {
        render(<DetailTeknisCollapsible storageKey="2026-05-10" stats={[...STATS]} />);
        fireEvent.click(screen.getByRole('button'));
        expect(screen.getByText('240')).toBeInTheDocument();
        expect(screen.getByText('28.4')).toBeInTheDocument();
    });

    it('persists the open state in localStorage keyed by storageKey', () => {
        render(<DetailTeknisCollapsible storageKey="2026-05-10" stats={[...STATS]} />);
        fireEvent.click(screen.getByRole('button'));
        expect(window.localStorage.getItem('aktivitas.detailTeknisOpen.2026-05-10')).toBe('1');
    });

    it('hydrates open from a stored "1" value on mount', () => {
        window.localStorage.setItem('aktivitas.detailTeknisOpen.2026-05-10', '1');
        render(<DetailTeknisCollapsible storageKey="2026-05-10" stats={[...STATS]} />);
        expect(screen.getByText('240')).toBeInTheDocument();
    });
});
