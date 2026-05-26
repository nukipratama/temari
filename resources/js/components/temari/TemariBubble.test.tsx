import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TemariBubble from './TemariBubble';
import type { AnalysisPayload, StoryLine } from '@/types/inertia';

function makeLine(overrides: Partial<StoryLine> = {}): StoryLine {
    return {
        id: 1,
        user_id: 1,
        activity_id: 1,
        kind: 'post_run',
        mood: 'enteng',
        speech: null,
        sigil_pattern: 'orct',
        for_date: null,
        ...overrides,
    };
}

function makeAnalysis(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'Run solid banget',
        type: 'post_run_speech',
        subject_type: String.raw`App\Models\Activity`,
        subject_id: 1,
        discriminator: null,
        ...overrides,
    };
}

describe('TemariBubble', () => {
    it('renders the resolved content when analysis status is done', () => {
        render(<TemariBubble line={makeLine()} speechAnalysis={makeAnalysis()} />);
        expect(screen.getByText('Run solid banget')).toBeInTheDocument();
    });

    it('renders the manual trigger CTA when status is pending', () => {
        render(
            <TemariBubble
                line={makeLine()}
                speechAnalysis={makeAnalysis({ status: 'pending', content: null })}
            />,
        );
        expect(screen.getByText(/Belum dibaca Temari/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Minta Temari bacain/ })).toBeInTheDocument();
    });

    it('renders UnavailableNote + retry CTA when status is failed', () => {
        render(
            <TemariBubble
                line={makeLine()}
                speechAnalysis={makeAnalysis({ status: 'failed', content: null })}
            />,
        );
        expect(screen.getByText(/Temari lagi diem dulu/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Coba lagi/ })).toBeInTheDocument();
    });

    it('renders sm size variant', () => {
        const { container } = render(
            <TemariBubble line={makeLine()} speechAnalysis={makeAnalysis()} size="sm" />,
        );
        expect(container.querySelector('.h-20')).toBeTruthy();
    });

    it('renders the mascot SVG inside the bubble', () => {
        const { container } = render(
            <TemariBubble line={makeLine()} speechAnalysis={makeAnalysis()} />,
        );
        expect(container.querySelector('svg')).toBeTruthy();
    });
});
