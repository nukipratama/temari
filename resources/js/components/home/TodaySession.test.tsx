import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import type { BriefingResult } from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import TodaySession from './TodaySession';

function briefing(content: string, status = 'done'): BriefingResult {
    return {
        vibeState: 'pumped',
        vibeLabel: 'Pumped',
        vibeEmoji: '💥',
        mascotVoice: {
            id: 4,
            status: status as BriefingResult['mascotVoice']['status'],
            content,
            type: 'briefing_mascot_voice',
            subject_type: 'briefing_user_day',
            subject_id: 1,
            discriminator: '2026-06-12',
        },
        recoveryLabel: 'Recovery: 41h',
        recoveryTone: 'positive',
        recoveryHoursLabel: '41h',
        recoveryHours: 41,
        streakLabel: 'Ran today',
        sigilPattern: 'orct',
        accessory: null,
        mood: 'blazing',
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('TodaySession', () => {
    it('leads with the opening line and follows with the rest', () => {
        render(
            <TodaySession
                briefing={briefing('Easy 6k.\n\nKeep it under 6:00.')}
            />,
        );

        expect(screen.getByText('Easy 6k.')).toBeInTheDocument();
        expect(screen.getByText('Keep it under 6:00.')).toBeInTheDocument();
    });

    it('labels the block as today', () => {
        render(<TodaySession briefing={briefing('Easy 6k.')} />);

        expect(screen.getByText('Today')).toBeInTheDocument();
    });

    it('renders a lead-only voice with no body paragraph', () => {
        render(<TodaySession briefing={briefing('“Just an easy one.”')} />);

        expect(screen.getByText('Just an easy one.')).toBeInTheDocument();
    });

    it('emits no voice when the content is whitespace only', () => {
        render(<TodaySession briefing={briefing('\n\n   \n\n')} />);

        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.queryByText(/Easy/)).not.toBeInTheDocument();
    });

    it('shows the thinking skeleton while the block is still queued', () => {
        render(<TodaySession briefing={briefing('', 'queued')} />);

        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it("renders Temari's mascot posed for the briefing's mood", () => {
        const { container } = render(
            <TodaySession briefing={briefing('Easy 6k.')} />,
        );

        expect(container.querySelector('.temari-root')).toHaveAttribute(
            'data-pose',
            'proud',
        );
    });
});
