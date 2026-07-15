import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TemariThread, { type ThreadEntry } from './TemariThread';
import { setMockPage } from '@/test/setup';
import type { AnalysisPayload } from '@/types/inertia';

function payload(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'hi',
        type: 'post_run_speech',
        subject_type: 'App\\Models\\Activity',
        subject_id: 42,
        discriminator: null,
        ...overrides,
    };
}

function entry(id: string, overrides: Partial<AnalysisPayload> = {}): ThreadEntry {
    return {
        id,
        icon: 'mdi:chat-outline',
        label: id,
        analysis: payload(overrides),
    };
}

describe('TemariThread grouped reanalyze button', () => {
    it('renders the grouped Analisis ulang button when all entries are done', () => {
        const entries: ThreadEntry[] = [
            entry('speech'),
            entry('technical', { type: 'run_insight_technical' }),
            entry('splits', { type: 'run_insight_splits' }),
            entry('zones', { type: 'run_insight_zones' }),
        ];
        render(<TemariThread mood="nyala" entries={entries} />);
        expect(screen.getByRole('button', { name: /Baca ulang/ })).toBeInTheDocument();
    });

    it('hides the grouped button when AI is globally paused', () => {
        setMockPage({ aiPaused: true });
        const entries: ThreadEntry[] = [
            entry('speech'),
            entry('technical', { type: 'run_insight_technical' }),
            entry('splits', { type: 'run_insight_splits' }),
            entry('zones', { type: 'run_insight_zones' }),
        ];
        render(<TemariThread mood="nyala" entries={entries} />);
        expect(screen.queryByRole('button', { name: /Baca ulang/ })).not.toBeInTheDocument();
    });

    it('disables the grouped button and shows a countdown while on cooldown', () => {
        const entries: ThreadEntry[] = [
            entry('speech', { retry_after_seconds: 900 }),
            entry('technical', { type: 'run_insight_technical' }),
            entry('splits', { type: 'run_insight_splits' }),
            entry('zones', { type: 'run_insight_zones' }),
        ];
        render(<TemariThread mood="nyala" entries={entries} />);
        const button = screen.getByRole('button', { name: 'Tunggu 15:00 sebelum baca ulang' });
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('15:00');
    });

    it('hides the grouped button when any entry is queued', () => {
        const entries: ThreadEntry[] = [
            entry('speech', { status: 'queued', content: null }),
            entry('technical', { type: 'run_insight_technical' }),
            entry('splits', { type: 'run_insight_splits' }),
            entry('zones', { type: 'run_insight_zones' }),
        ];
        render(<TemariThread mood="nyala" entries={entries} />);
        expect(screen.queryByRole('button', { name: /Baca ulang/ })).not.toBeInTheDocument();
    });

    it('hides the grouped button when any entry is processing', () => {
        const entries: ThreadEntry[] = [
            entry('speech'),
            entry('technical', { type: 'run_insight_technical', status: 'processing', content: null }),
            entry('splits', { type: 'run_insight_splits' }),
            entry('zones', { type: 'run_insight_zones' }),
        ];
        render(<TemariThread mood="nyala" entries={entries} />);
        expect(screen.queryByRole('button', { name: /Baca ulang/ })).not.toBeInTheDocument();
    });

    it('does not render the grouped button for a single-entry thread', () => {
        const entries: ThreadEntry[] = [entry('speech')];
        render(<TemariThread mood="nyala" entries={entries} />);
        // Single-entry threads use the per-row button instead of the grouped one.
        // The per-row button text is identical so we check there is exactly one.
        expect(screen.getAllByRole('button', { name: /Baca ulang/ })).toHaveLength(1);
    });
});
