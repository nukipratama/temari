import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import BriefingCard from './BriefingCard';
import type { AnalysisPayload, BriefingResult, Mood } from '@/types/inertia';

function analysisPayload(content: string | null, status: AnalysisPayload['status'] = 'done', type: AnalysisPayload['type'] = 'briefing_headline'): AnalysisPayload {
    return {
        id: 1,
        status,
        content,
        type,
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
    };
}

function makeBriefing(overrides: Partial<BriefingResult> = {}): BriefingResult {
    return {
        vibeState: 'fresh',
        vibeLabel: 'Segar',
        vibeEmoji: '✨',
        headline: analysisPayload('Pagi yang oke', 'done', 'briefing_headline'),
        suggestion: analysisPayload('Easy run aja dulu', 'done', 'briefing_suggestion'),
        recoveryLabel: 'Pemulihan: cukup',
        recoveryTone: 'positive',
        streakLabel: 'Lari hari ini',
        sigilPattern: 'orct',
        accessory: 'headband',
        mood: 'glow',
        ...overrides,
    };
}

describe('BriefingCard', () => {
    it('renders headline + suggestion + chips', () => {
        render(<BriefingCard briefing={makeBriefing()} />);
        expect(screen.getByText('Pagi yang oke')).toBeInTheDocument();
        expect(screen.getByText('Easy run aja dulu')).toBeInTheDocument();
        expect(screen.getByText('Pemulihan: cukup')).toBeInTheDocument();
        expect(screen.getByText(/Lari hari ini/)).toBeInTheDocument();
    });

    it('omits streak chip when null', () => {
        render(<BriefingCard briefing={makeBriefing({ streakLabel: null })} />);
        expect(screen.queryByText(/Lari hari ini/)).not.toBeInTheDocument();
    });

    it('shows UnavailableNote when LLM analysis failed', () => {
        render(
            <BriefingCard
                briefing={makeBriefing({
                    headline: analysisPayload(null, 'failed', 'briefing_headline'),
                })}
            />,
        );
        expect(screen.getByText(/belum tersedia/i)).toBeInTheDocument();
    });

    it('shows manual trigger CTA when LLM analysis pending', () => {
        render(
            <BriefingCard
                briefing={makeBriefing({
                    headline: analysisPayload(null, 'pending', 'briefing_headline'),
                })}
            />,
        );
        expect(screen.getByText(/Belum dianalisis Temari/)).toBeInTheDocument();
    });

    it.each([
        'pumped',
        'fresh',
        'bouncy',
        'cooked',
        'stretched_thin',
        'worn_down',
        'hibernating',
        'steady',
    ])('maps vibeState %s to a mood-coded left rule', (state) => {
        const { container } = render(<BriefingCard briefing={makeBriefing({ vibeState: state })} />);
        expect(container.firstChild).toHaveClass(/border-l-/);
    });

    it.each(['positive', 'warning', 'alert', 'neutral'] as const)('renders recovery tone %s', (tone) => {
        render(<BriefingCard briefing={makeBriefing({ recoveryTone: tone })} />);
    });

    it.each(['glow', 'bouncy', 'wobble', 'squished', 'spinning', 'dim'] satisfies Mood[])(
        'renders mood %s',
        (mood) => {
            render(<BriefingCard briefing={makeBriefing({ mood })} />);
        },
    );

    it('renders headline done content via renderContent when suggestion still pending', () => {
        render(
            <BriefingCard
                briefing={makeBriefing({
                    headline: analysisPayload('Pagi yang oke', 'done', 'briefing_headline'),
                    suggestion: analysisPayload(null, 'pending', 'briefing_suggestion'),
                })}
            />,
        );
        expect(screen.getByText('Pagi yang oke')).toBeInTheDocument();
    });
});
