import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
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
        mascotVoice: analysisPayload(null, 'pending', 'briefing_mascot_voice'),
        featuredKartuVoice: analysisPayload(null, 'pending', 'briefing_featured_kartu_voice'),
        recoveryLabel: 'Pemulihan: cukup',
        recoveryTone: 'positive',
        recoveryHoursLabel: '12j',
        streakLabel: 'Lari hari ini',
        sigilPattern: 'orct',
        accessory: 'headband',
        mood: 'nyala',
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
        expect(screen.getByText(/Temari lagi diem dulu/i)).toBeInTheDocument();
    });

    it('shows manual trigger CTA when LLM analysis pending', () => {
        render(
            <BriefingCard
                briefing={makeBriefing({
                    headline: analysisPayload(null, 'pending', 'briefing_headline'),
                })}
            />,
        );
        expect(screen.getByText(/Belum dibaca Temari/)).toBeInTheDocument();
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

    it.each(['nyala', 'enteng', 'lemes', 'oleng', 'mumet', 'adem'] satisfies Mood[])(
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

    it('shows queued spinner pill in footer when headline is being generated', () => {
        render(
            <BriefingCard
                briefing={makeBriefing({
                    headline: analysisPayload(null, 'queued', 'briefing_headline'),
                })}
            />,
        );
        expect(screen.getAllByText(/Lagi dipikirin Temari/).length).toBeGreaterThan(0);
    });

    it('renders **bold** markers in the mascot voice bubble as <strong>', () => {
        render(
            <BriefingCard
                briefing={makeBriefing({
                    mascotVoice: analysisPayload('dapet **PR** juga', 'done', 'briefing_mascot_voice'),
                })}
            />,
        );
        expect(screen.getByText('PR').tagName).toBe('STRONG');
    });

    it('ticks down the re-analyze cooldown each second', () => {
        vi.useFakeTimers();
        try {
            const payload: AnalysisPayload = {
                ...analysisPayload('Pagi yang oke', 'done', 'briefing_headline'),
                retry_after_seconds: 3,
            };
            render(<BriefingCard briefing={makeBriefing({ headline: payload })} />);
            expect(screen.getByText(/Tunggu 0:03/)).toBeInTheDocument();
            act(() => {
                vi.advanceTimersByTime(1000);
            });
            expect(screen.getByText(/Tunggu 0:02/)).toBeInTheDocument();
            act(() => {
                vi.advanceTimersByTime(2000);
            });
            expect(screen.getByText(/Baca ulang/)).toBeInTheDocument();
        } finally {
            vi.useRealTimers();
        }
    });
});
