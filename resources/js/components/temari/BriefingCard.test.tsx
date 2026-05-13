import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import BriefingCard from './BriefingCard';
import type { BriefingResult, Mood } from '@/types/inertia';

function makeBriefing(overrides: Partial<BriefingResult> = {}): BriefingResult {
    return {
        vibeState: 'fresh',
        vibeLabel: 'Segar',
        vibeEmoji: '✨',
        headlineLine: 'Pagi yang oke',
        suggestionLine: 'Easy run aja dulu',
        recoveryLabel: 'Pemulihan: cukup',
        recoveryTone: 'positive',
        streakLabel: 'Lari hari ini',
        sigilPattern: 'orct',
        accessory: 'headband',
        mood: 'glow',
        degraded: false,
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

    it('shows the degraded chip when LLM fallback triggered', () => {
        render(<BriefingCard briefing={makeBriefing({ degraded: true })} />);
        expect(screen.getByText(/mode darurat/i)).toBeInTheDocument();
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
    ])('maps vibeState %s to a background gradient class', (state) => {
        const { container } = render(<BriefingCard briefing={makeBriefing({ vibeState: state })} />);
        expect(container.firstChild).toHaveClass(/bg-gradient/);
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
});
