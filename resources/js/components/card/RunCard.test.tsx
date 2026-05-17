import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RunCard from './RunCard';
import type { ActivityDetail, RunCard as RunCardModel } from '@/types/inertia';

function card(overrides: Partial<RunCardModel> = {}): RunCardModel {
    return {
        id: 1,
        activity_id: 99,
        rarity: 'epik',
        special_move: 'Paru-paru Baja',
        badges: ['hari_panas', 'negative_split'],
        ...overrides,
    };
}

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 1,
        activity_id: 99,
        name: 'Tempo Run',
        start_date_local: '2026-05-10T08:00:00',
        distance: 10000,
        moving_time: 3600,
        average_heartrate: null,
        trimp_edwards: 70,
        ...overrides,
    };
}

describe('RunCard', () => {
    it('renders special move + rarity label', () => {
        render(<RunCard card={card()} detail={detail()} />);
        expect(screen.getByText('Paru-paru Baja')).toBeInTheDocument();
        expect(screen.getByText('Epic')).toBeInTheDocument();
    });

    it('renders detail name when present', () => {
        render(<RunCard card={card()} detail={detail()} />);
        expect(screen.getByText('Tempo Run')).toBeInTheDocument();
    });

    it('falls back to "Run" when detail.name is null', () => {
        render(<RunCard card={card()} detail={detail({ name: null })} />);
        expect(screen.getByText('Run')).toBeInTheDocument();
    });

    it.each(['biasa', 'jarang', 'langka', 'epik', 'legendaris'] as const)('renders rarity %s', (rarity) => {
        render(<RunCard card={card({ rarity })} detail={detail()} />);
    });

    it('renders badge labels when present', () => {
        render(<RunCard card={card()} detail={detail()} />);
        expect(screen.getByText(/Heat Beater/)).toBeInTheDocument();
        expect(screen.getByText(/Negative Split/)).toBeInTheDocument();
    });

    it('hides badge list when empty/null', () => {
        const { container } = render(<RunCard card={card({ badges: [] })} detail={detail()} />);
        expect(container.querySelector('ul')).toBeNull();
    });

    it('renders dashes for null numeric fields', () => {
        render(<RunCard card={card()} detail={detail({ distance: null, moving_time: null, trimp_edwards: null })} />);
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });

    it('falls back chip styling for unknown rarity', () => {
        // @ts-expect-error - intentionally pass an unknown rarity to hit default branch
        render(<RunCard card={card({ rarity: 'mythic' })} detail={detail()} />);
    });

    it('renders the raw badge key when no label is defined', () => {
        render(<RunCard card={card({ badges: ['custom_badge'] })} detail={detail()} />);
        expect(screen.getByText('custom_badge')).toBeInTheDocument();
    });

    it('renders at hero size when size="hero"', () => {
        const { container } = render(<RunCard card={card()} detail={detail()} size="hero" />);
        expect(container.firstElementChild?.className).toContain('ring-4');
    });
});
