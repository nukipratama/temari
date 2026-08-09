import { usePoll } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { StravaSyncState } from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import EmptyRunsState from './EmptyRunsState';

const HERO_COPY: Record<StravaSyncState, { headline: string; copy: string }> = {
    disconnected: {
        headline: 'Connect Strava first',
        copy: 'I read your runs straight from Strava. Connect it first to get your first card going.',
    },
    revoked: {
        headline: 'Strava connection lost',
        copy: "Your Strava token isn't active anymore. Reconnect so new runs can be read.",
    },
    syncing: {
        headline: 'Your runs are being pulled from Strava',
        copy: "Hang tight, the moment your first run comes in, I'll read it and the card will show up.",
    },
    ready: {
        headline: 'No new runs found yet',
        copy: 'If you just finished a run, try syncing again so it gets picked up.',
    },
};

function renderWithState(state: StravaSyncState) {
    const start = vi.fn();
    const stop = vi.fn();
    vi.mocked(usePoll).mockReturnValue({ start, stop });
    setMockPage({
        auth: { user: null },
        flash: {},
        demoLoginEnabled: false,
        stravaSync: { state, last_synced_at: null },
    });
    render(<EmptyRunsState />);
    return { start, stop };
}

function expectHeroContent(state: StravaSyncState) {
    const { headline, copy } = HERO_COPY[state];
    expect(screen.getByText(headline)).toBeInTheDocument();
    expect(screen.getByText(copy, { exact: false })).toBeInTheDocument();
}

function expectActionLinks() {
    expect(screen.getByText('While you wait')).toBeInTheDocument();

    const kartu = screen
        .getByText('Check out the legendary collection')
        .closest('a');
    expect(kartu).toHaveAttribute('href', '/kartu');

    const aksesori = screen.getByText('Dress up Temari').closest('a');
    expect(aksesori).toHaveAttribute('href', '/aksesori');

    const aktivitas = screen.getByText('See your run recap').closest('a');
    expect(aktivitas).toHaveAttribute('href', '/aktivitas');
}

describe('EmptyRunsState', () => {
    it('starts polling recentRuns + stravaSync while a sync is in flight', () => {
        const { start, stop } = renderWithState('syncing');

        expect(usePoll).toHaveBeenCalledWith(
            7000,
            { only: ['recentRuns', 'stravaSync'] },
            { autoStart: false },
        );
        expect(start).toHaveBeenCalled();
        expect(stop).not.toHaveBeenCalled();
        expectHeroContent('syncing');
        expectActionLinks();
    });

    it('does not start polling when disconnected', () => {
        const { start, stop } = renderWithState('disconnected');

        expect(start).not.toHaveBeenCalled();
        expect(stop).toHaveBeenCalled();
        expectHeroContent('disconnected');
        expectActionLinks();
    });

    it('does not start polling when revoked, and shows the reconnect copy', () => {
        const { start, stop } = renderWithState('revoked');

        expect(start).not.toHaveBeenCalled();
        expect(stop).toHaveBeenCalled();
        expectHeroContent('revoked');
        expectActionLinks();
    });

    it('stops polling once the sync reaches ready', () => {
        const { start, stop } = renderWithState('ready');

        expect(start).not.toHaveBeenCalled();
        expect(stop).toHaveBeenCalled();
        expectHeroContent('ready');
        expectActionLinks();
    });
});
