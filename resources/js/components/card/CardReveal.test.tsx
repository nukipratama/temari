import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import CardReveal from './CardReveal';
import type { PendingReveal } from '@/types/inertia';

const post = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const actual: typeof import('@inertiajs/react') = await vi.importActual('@inertiajs/react');
    return {
        ...actual,
        router: { post: (...args: unknown[]) => post(...args) },
    };
});

const epicReveal: PendingReveal = {
    card_id: 42,
    activity_id: 99,
    rarity: 'epic',
    special_move: 'Pembalik Keadaan',
    badges: ['negative_split', 'hari_panas'],
    detail_name: '10K race-pace',
};

const commonReveal: PendingReveal = {
    card_id: 7,
    activity_id: 12,
    rarity: 'common',
    special_move: 'Pagi Santai',
    badges: null,
    detail_name: 'Easy run',
};

describe('CardReveal', () => {
    it('renders the first frame eyebrow + title on mount', () => {
        render(<CardReveal pending={epicReveal} />);
        expect(screen.getByText('Sync masuk')).toBeInTheDocument();
        expect(screen.getByText(/Aku lagi baca lari kamu/)).toBeInTheDocument();
    });

    it('uses a 4-frame theatrical flow for epic+', () => {
        render(<CardReveal pending={epicReveal} />);
        expect(screen.getByText(/Frame 1 \/ 4/)).toBeInTheDocument();
    });

    it('uses a 2-frame intimate flow for common rarity', () => {
        render(<CardReveal pending={commonReveal} />);
        expect(screen.getByText(/Frame 1 \/ 2/)).toBeInTheDocument();
    });

    it('advances frames when the Lanjut button is tapped', async () => {
        const u = userEvent.setup();
        render(<CardReveal pending={epicReveal} />);
        expect(screen.getByText(/Frame 1 \/ 4/)).toBeInTheDocument();
        await u.click(screen.getByText('Lanjut'));
        expect(screen.getByText(/Frame 2 \/ 4/)).toBeInTheDocument();
    });

    it('posts to /api/kartu/{id}/seen on the final advance', async () => {
        post.mockClear();
        const u = userEvent.setup();
        render(<CardReveal pending={commonReveal} />);
        await u.click(screen.getByText('Lanjut'));
        // Last frame for common (frame 2 / 2)
        await u.click(screen.getByText('Lihat koleksi'));
        expect(post).toHaveBeenCalledWith(
            '/api/kartu/7/seen',
            expect.anything(),
            expect.objectContaining({ only: ['pendingReveal'] }),
        );
    });

    it('posts seen when Skip is tapped on the first frame', async () => {
        post.mockClear();
        const u = userEvent.setup();
        render(<CardReveal pending={epicReveal} />);
        await u.click(screen.getByText('Skip'));
        expect(post).toHaveBeenCalledWith(
            '/api/kartu/42/seen',
            expect.anything(),
            expect.anything(),
        );
    });

    it('Escape key dismisses the reveal modal', async () => {
        post.mockClear();
        render(<CardReveal pending={epicReveal} />);
        await userEvent.setup().keyboard('{Escape}');
        expect(post).toHaveBeenCalled();
    });

    it('Space + Enter + ArrowRight all advance the frame', async () => {
        const u = userEvent.setup();
        render(<CardReveal pending={epicReveal} />);
        expect(screen.getByText(/Frame 1 \/ 4/)).toBeInTheDocument();
        await u.keyboard(' ');
        expect(screen.getByText(/Frame 2 \/ 4/)).toBeInTheDocument();
        await u.keyboard('{Enter}');
        expect(screen.getByText(/Frame 3 \/ 4/)).toBeInTheDocument();
        await u.keyboard('{ArrowRight}');
        expect(screen.getByText(/Frame 4 \/ 4/)).toBeInTheDocument();
    });
});
