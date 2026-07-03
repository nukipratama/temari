import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import HariIni from './HariIni';
import { makeUser, setMockPage } from '@/test/setup';
import type {
    ActivityDetail,
    BriefingResult,
    GoalsSummary,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

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
    featuredKartuVoice: {
        id: 5,
        status: 'done',
        content: 'Kartu ini bukti kamu bisa lebih jauh dari yang kamu kira.',
        type: 'briefing_featured_kartu_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '7',
    },
    featuredCardId: 7,
    recoveryLabel: 'Pemulihan: 41j',
    recoveryTone: 'positive',
    recoveryHoursLabel: '41j',
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
        run_card: {
            id: 7,
            activity_id: 99,
            rarity: 'epic',
            special_move: 'Pembalik Keadaan',
            badges: ['negative_split', 'hari_panas'],
        },
    },
};

// A run with the full set of optional fields populated (weather, location,
// pace, trimp) so LastLariCard renders every conditional row.
const richRun: ActivityDetail = {
    ...detailWithCard,
    location_name: 'Gelora Bung Karno, Jakarta Pusat',
    weather_temp_c: 28,
    weather_humidity_pct: 70,
    weather_rain_detected: false,
};

// A bare run with no optional fields: no location, no weather, no pace
// (zero distance/time), no trimp, no name. Exercises every "—"/empty branch.
const bareRun: ActivityDetail = {
    id: 2,
    activity_id: 100,
    name: null,
    start_date_local: '2026-05-21T07:00',
    distance: 0,
    moving_time: 0,
    average_heartrate: null,
    trimp_edwards: null,
    location_name: null,
    weather_temp_c: null,
    weather_humidity_pct: null,
    weather_rain_detected: null,
};

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('HariIni', () => {
    it('renders the editorial greeting with first name + vibe subtitle', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.getByText(/Halo, Ada/)).toBeInTheDocument();
        // "membara" now appears both in the italic headline accent and as the
        // Vibe chip sub-label, so allow multiple matches.
        expect(screen.getAllByText(/membara/i).length).toBeGreaterThan(0);
    });

    it('renders the three vital chips (Vibe / Kesiapan / Recovery)', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        expect(screen.getByText('Vibe')).toBeInTheDocument();
        expect(screen.getByText('Kesiapan')).toBeInTheDocument();
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
        expect(screen.queryByText(/Kartu andalan dari Temari/)).not.toBeInTheDocument();
    });

    it('shows the suggestion text', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        expect(screen.getByText(/Tempo ringan, 35–45 menit\./)).toBeInTheDocument();
    });

    it('renders the Kondisi card with CTL / ATL / Strain / Monotony rows', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        ['Fondasi', 'Kelelahan', 'Beban', 'Variasi'].forEach((row) => {
            expect(screen.getByText(row)).toBeInTheDocument();
        });
    });

    it('no longer renders a "Kartu terakhir" strip; the featured hero replaces it', () => {
        render(
            <HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />,
        );
        // The kartu strip was removed from the dashboard. Cards now surface only
        // through the featured hero panel (eyebrow "Kartu andalan dari Temari").
        expect(screen.queryByText(/Kartu terakhir/i)).not.toBeInTheDocument();
        expect(screen.getByText(/Kartu andalan dari Temari/)).toBeInTheDocument();
    });

    it('renders the featuredKartuVoice quote inside the hero panel', () => {
        render(
            <HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />,
        );
        expect(screen.getAllByText(/bukti kamu bisa lebih jauh/).length).toBeGreaterThan(0);
    });

    it('renders without crashing when suggestion content is empty', () => {
        const emptyBriefing: BriefingResult = {
            ...briefing,
            suggestion: { ...briefing.suggestion, content: '' },
        };
        render(<HariIni briefing={emptyBriefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.getByText(/Halo, Ada/)).toBeInTheDocument();
    });

    it('renders the suggestion block but emits no content when the text is whitespace-only', () => {
        // recentRuns present so the SuggestionCard (and thus SuggestionContent)
        // actually mounts; whitespace-only content trims to zero parts -> null.
        const blankSuggestion: BriefingResult = {
            ...briefing,
            suggestion: { ...briefing.suggestion, content: '\n\n   \n\n' },
        };
        render(
            <HariIni briefing={blankSuggestion} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />,
        );
        // The section heading still renders; the body resolves to nothing.
        expect(screen.getByText('Saran sesi dari Temari')).toBeInTheDocument();
    });

    it('renders the suggestion title-only when there is no body paragraph', () => {
        const titleOnly: BriefingResult = {
            ...briefing,
            suggestion: { ...briefing.suggestion, content: '“Lari santai aja hari ini.”' },
        };
        render(
            <HariIni briefing={titleOnly} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />,
        );
        expect(screen.getByText(/Lari santai aja hari ini\./)).toBeInTheDocument();
    });

    it('toggles the Temari quote open/closed when the long-quote text overflows', () => {
        const longText = 'a'.repeat(200);
        const longQuoteBriefing: BriefingResult = {
            ...briefing,
            mascotVoice: { ...briefing.mascotVoice, content: longText },
        };
        render(<HariIni briefing={longQuoteBriefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        const toggle = screen.getByRole('button', { name: 'Baca selengkapnya' });
        fireEvent.click(toggle);
        expect(screen.getByRole('button', { name: 'Tutup' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Tutup' }));
        expect(screen.getByRole('button', { name: 'Baca selengkapnya' })).toBeInTheDocument();
    });

    it('does not render the expand toggle for a short Temari quote', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        expect(screen.queryByRole('button', { name: 'Baca selengkapnya' })).not.toBeInTheDocument();
    });

    it('renders the "Target terdekat" goals when goalsSummary has closest goals', () => {
        const goalsSummary: GoalsSummary = {
            total: 3,
            completed: 1,
            closest: [
                // whole numbers -> integer display, full progress capped at 100%
                { id: 'g1', title: 'Lari 100 KM bulan ini', current: 100, target: 100, unit: 'km' },
                // decimals -> toFixed(1) on both current and target
                { id: 'g2', title: 'Half marathon', current: 12.5, target: 21.1, unit: 'km' },
                // target 0 -> pct branch returns 0 (no divide-by-zero)
                { id: 'g3', title: 'Target kosong', current: 0, target: 0, unit: 'sesi' },
            ],
        };
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            goalsSummary,
        });
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        expect(screen.getByText('Target terdekat')).toBeInTheDocument();
        expect(screen.getByText('Lari 100 KM bulan ini')).toBeInTheDocument();
        expect(screen.getByText('Half marathon')).toBeInTheDocument();
        expect(screen.getByText('Target kosong')).toBeInTheDocument();
        // current/target sit in one span split by a "/" node; match the combined
        // text to confirm both the integer (100/100) and decimal (12.5/21.1) paths.
        expect(screen.getByText((_, el) => el?.textContent === '100/100')).toBeInTheDocument();
        expect(screen.getByText((_, el) => el?.textContent === '12.5/21.1')).toBeInTheDocument();
        // target 0 -> "0/0", no NaN/Infinity from the divide-by-zero guard.
        expect(screen.getByText((_, el) => el?.textContent === '0/0')).toBeInTheDocument();
    });

    it('omits the goals section when goalsSummary has no closest goals', () => {
        setMockPage({
            auth: { user: makeUser() },
            flash: {},
            demoLoginEnabled: false,
            goalsSummary: { total: 0, completed: 0, closest: [] },
        });
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        expect(screen.queryByText('Target terdekat')).not.toBeInTheDocument();
    });

    it('renders the last-run card with location, weather, pace, trimp, and a note', () => {
        render(
            <HariIni
                briefing={briefing}
                load={load}
                snapshot={snapshot}
                recentRuns={[richRun]}
                lastRunNote={{ oneline: 'Sesi yang mantap.', mood: 'nyala' }}
            />,
        );
        expect(screen.getByText('Pagi negatif-split')).toBeInTheDocument();
        expect(screen.getByText(/Gelora Bung Karno/)).toBeInTheDocument();
        expect(screen.getByText('Sesi yang mantap.')).toBeInTheDocument();
        // pace renders as a value (not the "—" fallback).
        expect(screen.getAllByText(/\/km$/).length).toBeGreaterThan(0);
    });

    it('renders the last-run card with em-dash fallbacks when pace/trimp/weather/location absent', () => {
        render(
            <HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[bareRun]} />,
        );
        // name falls back to "Lari"; pace + trimp both show the "—" placeholder.
        expect(screen.getByText('Lari')).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(2);
        // no location/weather row.
        expect(screen.queryByText(/Gelora/)).not.toBeInTheDocument();
    });

    it('falls back to an empty first name and the default pose for an unknown vibe', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        const oddBriefing = { ...briefing, vibeState: 'mysterious' as BriefingResult['vibeState'] };
        render(<HariIni briefing={oddBriefing} load={load} snapshot={snapshot} recentRuns={[]} />);
        // greeting renders with no name, no crash on the missing pose mapping.
        expect(screen.getByText(/Halo,/)).toBeInTheDocument();
    });

    it('shows em-dash / empty vital chips and "belum cukup data" when load and snapshot are null', () => {
        render(
            <HariIni briefing={briefing} load={null} snapshot={null} recentRuns={[detailWithCard]} />,
        );
        // Kesiapan + Kondisi rows all collapse to the "—" placeholder.
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
        expect(screen.getByText(/belum cukup data/)).toBeInTheDocument();
        // Vibe chip falls back to the qualitative label when there's no form score.
        expect(screen.getAllByText(/membara/i).length).toBeGreaterThan(0);
    });

    it('falls back to streakLabel for the Recovery chip when recoveryHoursLabel is null', () => {
        const noHours: BriefingResult = { ...briefing, recoveryHoursLabel: null };
        render(<HariIni briefing={noHours} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        expect(screen.getByText('Lari hari ini')).toBeInTheDocument();
    });

    it('falls back to recoveryLabel for the Recovery chip when hours + streak are both null', () => {
        const onlyRecovery: BriefingResult = {
            ...briefing,
            recoveryHoursLabel: null,
            streakLabel: null,
        };
        render(<HariIni briefing={onlyRecovery} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        expect(screen.getByText('Pemulihan: 41j')).toBeInTheDocument();
    });

    it('flips the "Saran lain" button to its pending label when triggered', () => {
        render(<HariIni briefing={briefing} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        const button = screen.getByRole('button', { name: 'Saran lain' });
        // trigger() flips `pending` synchronously before its fetch awaits, so the
        // re-render swaps the label to the in-flight copy.
        fireEvent.click(button);
        expect(screen.getByRole('button', { name: 'Lagi mikir…' })).toBeInTheDocument();
    });

    it('renders the suggestion as a title + body when the text has two paragraphs', () => {
        const withBody: BriefingResult = {
            ...briefing,
            suggestion: {
                ...briefing.suggestion,
                content: 'Tempo ringan hari ini.\n\nJaga pace di zona 2 selama 40 menit.',
            },
        };
        render(<HariIni briefing={withBody} load={load} snapshot={snapshot} recentRuns={[detailWithCard]} />);
        expect(screen.getByText('Tempo ringan hari ini.')).toBeInTheDocument();
        expect(screen.getByText(/Jaga pace di zona 2/)).toBeInTheDocument();
    });
});
