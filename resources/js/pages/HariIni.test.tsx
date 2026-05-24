import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import HariIni from './HariIni';
import { setMockPage } from '@/test/setup';
import type { ActivityDetail, BriefingResult, TrainingLoad, WeeklySnapshot } from '@/types/inertia';

const briefing: BriefingResult = {
    vibeState: 'pumped',
    vibeLabel: 'Membara',
    vibeEmoji: '💥',
    headline: {
        id: 1,
        status: 'done',
        content: 'Pagi yang oke',
        type: 'briefing_headline',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
    },
    suggestion: {
        id: 2,
        status: 'done',
        content: 'Tempo ringan, 35–45 menit.',
        type: 'briefing_suggestion',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
    },
    mascotVoice: {
        id: 4,
        status: 'done',
        content: 'Dua lari terakhirmu negatif-split.',
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
    },
    recoveryLabel: 'Pemulihan: 41j',
    recoveryTone: 'positive',
    streakLabel: 'Lari hari ini',
    sigilPattern: 'orct',
    accessory: null,
    mood: 'nyala',
};

const load: TrainingLoad = {
    form: -2.5,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    monotony: 1.2,
    strain: 384,
};

const snapshot: WeeklySnapshot = {
    id: 1,
    user_id: 1,
    week_ending: '2026-05-11',
    runs: 4,
    distance_km: 35.5,
    ctl_42d: 42,
    atl_7d: 44.5,
    form: -2.5,
    avg_decoupling: 3.2,
};

const detailWithCard: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Pagi negatif-split',
    start_date_local: '2026-05-20T07:00',
    distance: 5280,
    moving_time: 2400,
    average_heartrate: 145,
    trimp_edwards: 87,
    activity: {
        id: 99,
        user_id: 1,
        analyzed_at: '2026-05-20T08:00',
        runCard: {
            id: 7,
            activity_id: 99,
            rarity: 'epic',
            special_move: 'Pembalik Keadaan',
            badges: ['negative_split', 'hari_panas'],
        },
    },
};

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada Lovelace', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
        onboarding: { forceShow: false },
    });
});

describe('HariIni', () => {
    it('renders the editorial greeting with first name + vibe subtitle', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.getByText(/Halo, Ada/)).toBeInTheDocument();
        expect(screen.getByText(/membara/)).toBeInTheDocument();
    });

    it('renders the three vital chips (Vibe / Form / Recovery)', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.getByText('Vibe')).toBeInTheDocument();
        expect(screen.getByText('Form')).toBeInTheDocument();
        expect(screen.getByText('Recovery')).toBeInTheDocument();
    });

    it('shows the Temari read quote when mascotVoice is done', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.getAllByText(/negatif-split/).length).toBeGreaterThan(0);
    });

    it('renders the featured hero kartu when a recentRun has an attached runCard', () => {
        render(
            <HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />,
        );
        expect(screen.getAllByText('Pembalik Keadaan').length).toBeGreaterThan(0);
    });

    it('omits the hero panel when no recent run has a card', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.queryByText(/Kartu dari Temari minggu ini/)).not.toBeInTheDocument();
    });

    it('shows the suggestion text', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.getByText(/Tempo ringan, 35–45 menit\./)).toBeInTheDocument();
    });

    it('renders the Kondisi card with CTL / ATL / Strain / Monotony rows', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        ['Fondasi', 'Kelelahan', 'Beban', 'Variasi'].forEach((row) => {
            expect(screen.getByText(row)).toBeInTheDocument();
        });
    });

    it('renders the kartu strip when recent runs have cards', () => {
        render(
            <HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />,
        );
        expect(screen.getByText(/Yang Temari kasih ke kamu/)).toBeInTheDocument();
    });
});
