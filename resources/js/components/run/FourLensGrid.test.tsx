import { render, screen, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
    beforeEach(() => {
        render(<FourLensGrid {...defaultProps} />);
    });

    it('renders the four lens cards with their labels', () => {
        expect(screen.getByText('Cerita lari ini')).toBeInTheDocument();
        expect(screen.getByText('Terjemahan teknis')).toBeInTheDocument();
        expect(screen.getByText('Split paling seru')).toBeInTheDocument();
        expect(screen.getByText('Zona HR')).toBeInTheDocument();
    });

    it('renders the "Baca ulang semua" button', () => {
        expect(screen.getByText(/Baca ulang semua/i)).toBeInTheDocument();
    });

    it('renders analysis content when status is done', () => {
        expect(screen.getByText('Cerita lari ini.')).toBeInTheDocument();
    });

    it('disables the bulk trigger button while pending', () => {
        globalThis.fetch = vi.fn(() => new Promise(() => {})) as typeof fetch;
        fireEvent.click(screen.getByText(/Baca ulang semua/i).closest('button') as Element);
        expect(screen.getByText(/Lagi dibaca/i)).toBeInTheDocument();
    });
});
