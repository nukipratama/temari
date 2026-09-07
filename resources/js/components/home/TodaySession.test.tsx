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

    it('leads with the opening sentence when the narrator skipped its paragraph break', () => {
        render(
            <TodaySession
                briefing={briefing(
                    '25.5 km this week, 5 runs, and the line still points down in fitness. that\u2019s why I\u2019m keeping this to an easy one, 25\u201335 minutes with a short warmup.',
                )}
            />,
        );

        expect(
            screen.getByText(
                '25.5 km this week, 5 runs, and the line still points down in fitness.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'that\u2019s why I\u2019m keeping this to an easy one, 25\u201335 minutes with a short warmup.',
            ),
        ).toBeInTheDocument();
    });

    it('does not mistake a decimal for the end of the opening sentence', () => {
        render(
            <TodaySession
                briefing={briefing('You ran 25.5 km. Take it easy today.')}
            />,
        );

        expect(screen.getByText('You ran 25.5 km.')).toBeInTheDocument();
        expect(screen.getByText('Take it easy today.')).toBeInTheDocument();
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

    it("renders Temari's face on the leaf ring the prototype's today card uses", () => {
        const { container } = render(
            <TodaySession briefing={briefing('Easy 6k.')} />,
        );

        const face = container.querySelector('[data-face-icon]');
        expect(face).toBeInTheDocument();
        expect(face?.querySelector('circle')).toHaveAttribute(
            'stroke',
            'var(--color-leaf)',
        );
    });
});
