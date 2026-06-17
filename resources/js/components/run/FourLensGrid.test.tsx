import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
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
        globalThis.fetch = vi.fn(() => new Promise(() => {})) as typeof fetch;
        render(<FourLensGrid {...defaultProps} isChainHead />);
        fireEvent.click(screen.getByText(/Baca ulang semua/i).closest('button') as Element);
        expect(screen.getByText(/Lagi dibaca/i)).toBeInTheDocument();
    });
});
