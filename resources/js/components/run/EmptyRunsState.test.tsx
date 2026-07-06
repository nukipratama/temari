import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { usePoll } from '@inertiajs/react';
import EmptyRunsState from './EmptyRunsState';
import { setMockPage } from '@/test/setup';
import type { StravaSyncState } from '@/types/inertia';

const HERO_COPY: Record<StravaSyncState, { headline: string; copy: string }> = {
    disconnected: {
        headline: 'Sambungin Strava dulu',
        copy: 'Aku baca lari kamu langsung dari Strava. Sambungin dulu biar kartu pertamamu mulai jalan.',
    },
    revoked: {
        headline: 'Sambungan Strava putus',
        copy: 'Token Strava kamu udah gak aktif. Sambungin lagi yuk biar lari baru kebaca.',
    },
    syncing: {
        headline: 'Lari kamu lagi ditarik dari Strava',
        copy: 'Sebentar ya, begitu lari pertamamu masuk, aku langsung baca dan kartunya muncul.',
    },
    ready: {
        headline: 'Belum nemu lari baru',
        copy: 'Kalau kamu baru kelar lari, coba sync lagi biar langsung kebaca.',
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
    expect(screen.getByText('Sambil nungguin')).toBeInTheDocument();

    const kartu = screen.getByText('Cek koleksi yang legendaris').closest('a');
    expect(kartu).toHaveAttribute('href', '/kartu');

    const aksesori = screen.getByText('Dandanin Temari').closest('a');
    expect(aksesori).toHaveAttribute('href', '/aksesori');

    const riwayat = screen.getByText('Lihat rekap lari kamu').closest('a');
    expect(riwayat).toHaveAttribute('href', '/riwayat');
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
