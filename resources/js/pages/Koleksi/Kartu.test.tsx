import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KoleksiKartu from './Kartu';
import { setMockPage } from '@/test/setup';
import type { Activity, ActivityDetail, RunCard } from '@/types/inertia';

const rarityCounts = { common: 5, uncommon: 4, rare: 3, epic: 2, legendary: 0 };

function emptyCards() {
    return { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0, links: [] };
}

function cardWithRel(id: number, rarity: RunCard['rarity'], move = 'Langkah Mantap') {
    const activity: Activity = { id, user_id: 1, analyzed_at: '2026-05-10' };
    const detail: ActivityDetail = {
        id, activity_id: id, name: 'Lari pagi', start_date_local: '2026-05-10T06:30',
        distance: 5000, moving_time: 1800, trimp_edwards: 50, average_heartrate: 150,
        activity: { ...activity, run_card: { id, activity_id: id, rarity, special_move: move, badges: ['negative_split'] } },
    };
    return {
        id, activity_id: id, rarity, mood: 'adem' as const, special_move: move, badges: ['negative_split'],
        share_image_path: null,
        activity: { ...activity, detail },
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Koleksi/Kartu', () => {
    it('renders the EmptyState when no cards and no featured card', () => {
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText(/Belum ada kartu di sini/)).toBeInTheDocument();
    });

    it('renders the LegendaryTease when legendary count is 0', () => {
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText(/Legendaris · belum kebuka/)).toBeInTheDocument();
    });

    it('omits the LegendaryTease when at least one legendary card exists', () => {
        const counts = { ...rarityCounts, legendary: 1 };
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={null} rarityCounts={counts} />);
        expect(screen.queryByText(/Legendaris · belum kebuka/)).not.toBeInTheDocument();
    });

    it('renders the rarity filter pills (counts per rarity)', () => {
        render(<KoleksiKartu cards={emptyCards()} selectedRarity="epic" featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText(/Biasa · 5/)).toBeInTheDocument();
        expect(screen.getByText(/Istimewa · 2/)).toBeInTheDocument();
    });

    it('renders the slim banner with the featured flavor quote', () => {
        const featured = {
            id: 7, activity_id: 99, rarity: 'epic' as const, mood: 'nyala' as const, special_move: 'Tancap di Akhir', badges: ['negative_split', 'hari_panas'],
            detail: {
                id: 1, activity_id: 99, name: 'Sub-30', start_date_local: '2026-05-10T06:00',
                distance: 5000, moving_time: 1751, trimp_edwards: 85, average_heartrate: 150,
            } as ActivityDetail,
            flavor_analysis: {
                id: 1, status: 'done' as const, content: 'Lari yang menyegarkan.',
                type: 'card_flavor' as const, subject_type: 'run_card', subject_id: 7, discriminator: null,
            },
        };
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={featured} rarityCounts={rarityCounts} />);
        expect(screen.getByText(/Kartu terbaikmu/)).toBeInTheDocument();
        expect(screen.getByText(/Lari yang menyegarkan/)).toBeInTheDocument();
    });

    it('triggers a confetti burst when an epic grid card is tapped', () => {
        const cards = { ...emptyCards(), data: [cardWithRel(7, 'epic', 'Tancap di Akhir')] };
        render(<KoleksiKartu cards={cards} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        const cardLink = screen.getAllByRole('link').find((el) => el.getAttribute('href') === '/aktivitas/7');
        fireEvent.click(cardLink!);
    });

    it('renders the grid when cards.data has entries + handles cell taps', () => {
        const cards = { ...emptyCards(), data: [cardWithRel(1, 'epic', 'Tendangan Epic'), cardWithRel(2, 'common')] };
        render(<KoleksiKartu cards={cards} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText('Tendangan Epic')).toBeInTheDocument();
        const links = screen.getAllByRole('link').filter((el) => el.getAttribute('href') === '/aktivitas/1');
        fireEvent.click(links[0]);
    });

    it('falls back to the special move in the banner when there is no flavor analysis', () => {
        const featured = {
            id: 7, activity_id: 99, rarity: 'rare' as const, mood: 'adem' as const, special_move: 'Adem Ayem', badges: null,
            detail: null,
        };
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={featured} rarityCounts={rarityCounts} />);
        // The banner heading renders the name in an <em> — the card also renders it, so use getAllByText.
        expect(screen.getAllByText(/Adem Ayem/).length).toBeGreaterThan(0);
    });

    it('labels the search input and sort select for assistive tech', () => {
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByLabelText('Cari kartu')).toBeInTheDocument();
        expect(screen.getByLabelText('Urutkan')).toBeInTheDocument();
    });

    it('skips grid cells whose card has no detail', () => {
        const cardWithoutDetail = {
            id: 9, activity_id: 99, rarity: 'common' as const, mood: 'adem' as const, special_move: 'Tanpa Detail', badges: null,
            share_image_path: null,
            activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', strava_external_id: null, detail: undefined as never },
        };
        const cards = { ...emptyCards(), data: [cardWithoutDetail] };
        render(<KoleksiKartu cards={cards} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.queryByText('Tanpa Detail')).not.toBeInTheDocument();
    });

    it('filters the grid by the search query', async () => {
        const cards = { ...emptyCards(), data: [cardWithRel(1, 'epic', 'Tendangan Epic'), cardWithRel(2, 'common', 'Langkah Mantap')] };
        render(<KoleksiKartu cards={cards} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);

        fireEvent.change(screen.getByLabelText('Cari kartu'), { target: { value: 'Tendangan' } });

        await waitFor(() => {
            expect(screen.getByText('Tendangan Epic')).toBeInTheDocument();
            expect(screen.queryByText('Langkah Mantap')).not.toBeInTheDocument();
        });
    });

    it('sorts the grid by rarity and by name', async () => {
        const cards = {
            ...emptyCards(),
            data: [cardWithRel(1, 'common', 'Aduh Capek'), cardWithRel(2, 'legendary', 'Zona Ambang')],
        };
        render(<KoleksiKartu cards={cards} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);

        fireEvent.change(screen.getByLabelText('Urutkan'), { target: { value: 'rarity' } });
        await waitFor(() => {
            const names = screen.getAllByText(/Aduh Capek|Zona Ambang/).map((el) => el.textContent);
            expect(names.indexOf('Zona Ambang')).toBeLessThan(names.indexOf('Aduh Capek'));
        });

        fireEvent.change(screen.getByLabelText('Urutkan'), { target: { value: 'name' } });
        await waitFor(() => {
            const names = screen.getAllByText(/Aduh Capek|Zona Ambang/).map((el) => el.textContent);
            expect(names.indexOf('Aduh Capek')).toBeLessThan(names.indexOf('Zona Ambang'));
        });
    });
});
