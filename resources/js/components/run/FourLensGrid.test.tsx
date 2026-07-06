import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import FourLensGrid from './FourLensGrid';
import type { AnalysisPayload } from '@/types/inertia';

function makeAnalysis(id: number, type: AnalysisPayload['type'], status: 'done' | 'pending' = 'done', content = 'Hasil analisis.'): AnalysisPayload {
    return { id, status, content: status === 'done' ? content : null, type, subject_type: 'Activity', subject_id: 1, discriminator: null };
}

const defaultProps = {
    cerita: makeAnalysis(1, 'post_run_speech', 'done', 'Cerita lari ini.'),
    terjemahan: makeAnalysis(2, 'run_insight_technical', 'done', 'Terjemahan teknis.'),
    split: makeAnalysis(3, 'run_insight_splits', 'done', 'Split per km.'),
    hr: makeAnalysis(4, 'run_insight_zones', 'done', 'Zona HR.'),
};

describe('FourLensGrid', () => {
    it('renders the four lens cards with their labels', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        expect(screen.getByText('Cerita lari ini')).toBeInTheDocument();
        expect(screen.getByText('Terjemahan teknis')).toBeInTheDocument();
        expect(screen.getByText('Split paling seru')).toBeInTheDocument();
        expect(screen.getByText('Zona HR')).toBeInTheDocument();
    });

    it('renders analysis content when status is done', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        expect(screen.getByText('Cerita lari ini.')).toBeInTheDocument();
    });

    it('shows the head-only "Baca ulang semua" button on the chain head', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        expect(screen.getByText(/Baca ulang semua/i)).toBeInTheDocument();
    });

    it('hides the "Baca ulang semua" button on a historical (non-head) run', () => {
        render(<FourLensGrid {...defaultProps} />);
        expect(screen.queryByText(/Baca ulang semua/i)).not.toBeInTheDocument();
    });

    it('disables the bulk trigger button while pending', () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        render(<FourLensGrid {...defaultProps} isChainHead />);
        fireEvent.click(screen.getByText(/Baca ulang semua/i).closest('button') as Element);
        expect(screen.getByText(/Lagi dibaca/i)).toBeInTheDocument();
    });

    it('reloads via inertia and re-enables the button once the bulk trigger settles', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response('{}', { status: 200 }))));
        render(<FourLensGrid {...defaultProps} isChainHead />);

        fireEvent.click(screen.getByText(/Baca ulang semua/i).closest('button') as Element);

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalledWith({
                only: ['speechAnalysis', 'insightTechnical', 'insightSplits', 'insightZones'],
            });
        });
        await waitFor(() => {
            expect(screen.getByText('Baca ulang semua')).toBeInTheDocument();
        });
        expect(screen.getByText(/Baca ulang semua/i).closest('button')).not.toBeDisabled();
    });

    it('drops the per-lens reanalyze buttons on the head run', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        // The single "Baca ulang semua" control replaces every per-lens "Baca ulang".
        expect(screen.queryByText(/^Baca ulang$/i)).not.toBeInTheDocument();
        expect(screen.getByText(/Baca ulang semua/i)).toBeInTheDocument();
    });

    it('shows the shared cooldown countdown on the bulk button', () => {
        const cooling = {
            ...defaultProps,
            cerita: { ...defaultProps.cerita, retry_after_seconds: 120 },
        };
        render(<FourLensGrid {...cooling} isChainHead />);
        const button = screen.getByRole('button', { name: /Tunggu 2:00 sebelum baca ulang semua/i });
        expect(button).toBeDisabled();
        expect(button.textContent).toContain('2:00');
    });
});
