import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RingkasanCard from './RingkasanCard';
import type { AnalysisPayload } from '@/types/inertia';

const baseAnalysis = (overrides: Partial<AnalysisPayload> = {}): AnalysisPayload => ({
    id: 1,
    status: 'pending',
    content: null,
    type: 'weekly_recap',
    subject_type: String.raw`App\Models\WeeklySnapshot`,
    subject_id: 100,
    discriminator: null,
    ...overrides,
});

describe('RingkasanCard', () => {
    it('shows the fallback prose when the analysis is not yet done', () => {
        render(<RingkasanCard analysis={baseAnalysis()} fallback="Minggu ini lo lari 3x sejauh 12.5km." />);
        expect(screen.getByText('Minggu ini lo lari 3x sejauh 12.5km.')).toBeInTheDocument();
    });

    it('renders the LLM-generated narrative when the analysis is done', () => {
        const done = baseAnalysis({
            status: 'done',
            content: 'Minggu ini kamu lari tiga kali, pace semakin halus.',
        });
        render(<RingkasanCard analysis={done} fallback="ignored" />);
        expect(screen.getByText('Minggu ini kamu lari tiga kali, pace semakin halus.')).toBeInTheDocument();
        // Fallback should not double-render when the LLM content is available.
        expect(screen.queryByText('ignored')).not.toBeInTheDocument();
    });

    it('hides the manual trigger but keeps the fallback prose for a past week with no narration yet', () => {
        render(<RingkasanCard analysis={baseAnalysis()} fallback="fallback" />);
        expect(screen.queryByRole('button', { name: /Minta Temari bacain/ })).not.toBeInTheDocument();
        expect(screen.getByText('fallback')).toBeInTheDocument();
    });

    it('suppresses the trigger and labels the fallback as a preview for the current week', () => {
        render(<RingkasanCard analysis={baseAnalysis()} fallback="fallback" awaitingSchedule />);
        expect(screen.getByText(/Rekap minggu ini belum tersedia/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Minta Temari bacain/ })).not.toBeInTheDocument();
        // Still visible (never-empty intent preserved) but framed as a preview
        // instead of stacking unlabeled under "belum tersedia" — that read as a
        // contradiction (not ready yet, immediately followed by the summary).
        expect(screen.getByText('Sementara ini:')).toBeInTheDocument();
        expect(screen.getByText('fallback')).toBeInTheDocument();
    });
});
