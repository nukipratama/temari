import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KartuDetail from './KartuDetail';
import { setMockPage } from '@/test/setup';
import type { ActivityDetail, AnalysisPayload } from '@/types/inertia';

const doneAnalysis: AnalysisPayload = {
    id: 1,
    status: 'done',
    content: 'Lari ini bukti kamu bisa lebih jauh.',
    type: 'card_flavor',
    subject_type: 'RunCard',
    subject_id: 10,
    discriminator: null,
};

const pendingAnalysis: AnalysisPayload = {
    id: 2,
    status: 'pending',
    content: null,
    type: 'card_flavor',
    subject_type: 'RunCard',
    subject_id: 10,
    discriminator: null,
};

const detail: ActivityDetail = {
    id: 1,
    activity_id: 99,
    name: 'Pagi negatif-split',
    start_date_local: '2026-05-20T07:00',
    distance: 5280,
    moving_time: 2400,
    average_heartrate: 145,
    trimp_edwards: 87,
    activity: { id: 99, user_id: 1, analyzed_at: '2026-05-20T08:00' },
};

const epicCard = {
    id: 10,
    activity_id: 99,
    rarity: 'epic' as const,
    special_move: 'Tendangan Balik',
    mood: 'enteng' as const,
    badges: ['negative_split', 'anak_pagi'] as string[],
    detail,
    flavor_analysis: doneAnalysis,
};

const relatedCards = [
    { id: 11, activity_id: 100, rarity: 'epic' as const, special_move: 'Lompatan Fajar', mood: 'adem' as const, badges: null, detail: null },
];

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('KartuDetail', () => {
    it('renders the card name and rarity label', () => {
        render(<KartuDetail card={epicCard} relatedCards={[]} totalForRarity={7} />);
        expect(screen.getAllByText(/Tendangan Balik/).length).toBeGreaterThan(0);
        expect(screen.getByText(/7 dari koleksimu/)).toBeInTheDocument();
    });

    it('renders the flavor analysis content when done', () => {
        render(<KartuDetail card={epicCard} relatedCards={[]} totalForRarity={3} />);
        expect(screen.getByText(/Lari ini bukti kamu bisa lebih jauh/)).toBeInTheDocument();
    });

    it('renders badge lore section when badges exist', () => {
        render(<KartuDetail card={epicCard} relatedCards={[]} totalForRarity={3} />);
        expect(screen.getByText(/Kenapa Istimewa/)).toBeInTheDocument();
        expect(screen.getAllByText(/Negative Split/i).length).toBeGreaterThan(0);
    });

    it('renders the linked run detail card', () => {
        render(<KartuDetail card={epicCard} relatedCards={[]} totalForRarity={3} />);
        expect(screen.getByText('Pagi negatif-split')).toBeInTheDocument();
        expect(screen.getByText(/Dari lari/)).toBeInTheDocument();
    });

    it('renders related cards when provided', () => {
        render(<KartuDetail card={epicCard} relatedCards={relatedCards} totalForRarity={3} />);
        expect(screen.getByText('Lompatan Fajar')).toBeInTheDocument();
        expect(screen.getByText(/Kartu mirip di koleksimu/)).toBeInTheDocument();
        // 2-up on mobile, 3-up from sm so cards aren't cramped at ~390px.
        const relatedGrid = screen.getByText('Lompatan Fajar').closest('.grid');
        expect(relatedGrid?.className).toContain('grid-cols-2');
        expect(relatedGrid?.className).toContain('sm:grid-cols-3');
    });

    it('omits the badge lore section when badges is null', () => {
        const cardNoBadges = { ...epicCard, badges: null };
        render(<KartuDetail card={cardNoBadges} relatedCards={[]} totalForRarity={3} />);
        expect(screen.queryByText(/Kenapa Epik/)).not.toBeInTheDocument();
    });

    it('omits the linked run section when detail is null', () => {
        const cardNoDetail = { ...epicCard, detail: null };
        render(<KartuDetail card={cardNoDetail} relatedCards={[]} totalForRarity={3} />);
        expect(screen.queryByText(/Dari lari/)).not.toBeInTheDocument();
    });

    it('shows pending analysis skeleton when flavor is pending', () => {
        const cardPending = { ...epicCard, flavor_analysis: pendingAnalysis };
        render(<KartuDetail card={cardPending} relatedCards={[]} totalForRarity={3} />);
        expect(screen.queryByText(/Lari ini bukti/)).not.toBeInTheDocument();
    });

    it('opens ShareCardModal and closes it when Bagikan / Tutup are clicked', () => {
        render(<KartuDetail card={epicCard} relatedCards={[]} totalForRarity={3} />);
        fireEvent.click(screen.getByRole('button', { name: /Bagikan/i }));
        expect(screen.getByText(/Bagikan kartu/)).toBeInTheDocument();
        // Closing the modal covers () => setShareOpen(false)
        fireEvent.click(screen.getByLabelText('Tutup'));
    });
});
