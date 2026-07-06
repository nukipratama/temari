import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import FeaturedKartuPanel from './FeaturedKartuPanel';
import type { FeaturedCard } from '@/pages/HariIni/helpers';
import type { AnalysisPayload } from '@/types/inertia';

const featured: FeaturedCard = {
    cardId: 7,
    activityId: 42,
    name: 'Pembalik Keadaan',
    subtitle: 'Epik · 2 hari lalu',
    km: '5.28',
    durasi: '40:00',
    trimp: '87',
    rarity: 'epic',
    mood: 'nyala',
    badges: ['negative_split'],
    stats: { pace: '5:30/km' },
    zonePct: null,
    polyline: null,
    paceShape: [],
    startDate: '2026-05-20T07:00',
};

const voice: AnalysisPayload = {
    id: 5,
    status: 'done',
    content: 'Kartu ini bukti kamu bisa lebih jauh.',
    type: 'briefing_featured_kartu_voice',
    subject_type: 'briefing_user_day',
    subject_id: 1,
    discriminator: '2026-05-18',
};

describe('FeaturedKartuPanel', () => {
    it('renders the eyebrow, card name, and a CTA to the run', () => {
        render(<FeaturedKartuPanel featured={featured} featuredKartuVoice={voice} />);
        expect(screen.getByText(/Kartu andalan dari Temari/)).toBeInTheDocument();
        expect(screen.getAllByText('Pembalik Keadaan').length).toBeGreaterThan(0);
        const cta = screen.getByRole('link', { name: /lihat aktivitas/i });
        expect(cta).toHaveAttribute('href', '/aktivitas/42');
    });

    it('renders the featured-kartu voice quote', () => {
        render(<FeaturedKartuPanel featured={featured} featuredKartuVoice={voice} />);
        expect(screen.getByText(/bukti kamu bisa lebih jauh/)).toBeInTheDocument();
    });
});
