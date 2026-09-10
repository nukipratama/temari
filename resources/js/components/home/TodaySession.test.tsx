import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import type { BriefingResult, WeekPlanDay } from '@/types/inertia';

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
        mood: 'blazing',
    };
}

function day(overrides: Partial<WeekPlanDay> = {}): WeekPlanDay {
    return {
        id: 1,
        date: '2026-06-12',
        phase: 'build',
        session_type: 'easy',
        segments: [
            {
                key: 'main',
                minutes: 48,
                zone: 'Z2',
                pace_label: 'easy',
                km: 5.2,
                pace_sec_per_km: 360,
            },
        ],
        distance_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        clamp: null,
        actual_km: null,
        activities: [],
        ...overrides,
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

    it('starts the voice at the card edge, outside the mascot row', () => {
        render(<TodaySession briefing={briefing('Easy 6k.')} today={day()} />);

        const mascotRow = screen.getByText('Today').closest('div')
            ?.parentElement as HTMLElement;

        expect(mascotRow).not.toContainElement(screen.getByText('Easy 6k.'));
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

    it('states the session, its distance and its pace above the voice', () => {
        render(
            <TodaySession
                briefing={briefing('Easy 6k.')}
                today={day({ session_type: 'long', distance_km: 15 })}
            />,
        );

        expect(
            screen.getByText('long run · 15 km · 6:00/km'),
        ).toBeInTheDocument();
    });

    it('states the eased session beside the one the plan asked for, and why', () => {
        render(
            <TodaySession
                briefing={briefing('Easy 6k.')}
                today={day({
                    session_type: 'long',
                    distance_km: 15,
                    clamp: {
                        session_type: 'easy',
                        distance_km: 5.9,
                        pace_sec_per_km: 450,
                        note: "You've already run today, so anything else stays easy.",
                        label: 'anything else today',
                    },
                })}
            />,
        );

        expect(
            screen.getByText('long run · 15 km · 6:00/km'),
        ).toBeInTheDocument();
        expect(screen.getByText('anything else today')).toBeInTheDocument();
        expect(screen.getByText('easy · 5.9 km · 7:30/km')).toBeInTheDocument();

        const note = screen.getByText(
            "You've already run today, so anything else stays easy.",
        );
        expect(note).toBeInTheDocument();
        expect(note).toHaveClass('text-text-2');
        expect(note).not.toHaveClass('italic');
    });

    it('names a rest day with no distance or pace hung off it', () => {
        render(
            <TodaySession
                briefing={briefing('Easy 6k.')}
                today={day({
                    session_type: 'rest',
                    distance_km: 0,
                    segments: [],
                })}
            />,
        );

        expect(screen.getByText('rest')).toBeInTheDocument();
    });

    it('states both figures once the day has been judged', () => {
        render(
            <TodaySession
                briefing={briefing('Easy 6k.')}
                today={day({
                    status: 'done',
                    compliance_score: 100,
                    distance_km: 8,
                    prescribed_km: 6,
                    actual_km: 6.4,
                })}
            />,
        );

        expect(
            screen.getByText('easy · 6 km asked · 6.4 km run · 6:00/km'),
        ).toBeInTheDocument();
    });

    it('draws no prescription when no plan covers today', () => {
        const { container } = render(
            <TodaySession briefing={briefing('Easy 6k.')} />,
        );

        expect(screen.getByText('Easy 6k.')).toBeInTheDocument();
        expect(
            container.querySelector('#anchor-session-today'),
        ).not.toBeInTheDocument();
    });

    it("carries the citation anchor the briefing's prose points at", () => {
        const { container } = render(
            <TodaySession briefing={briefing('Easy 6k.')} today={day()} />,
        );

        expect(
            container.querySelector('#anchor-session-today'),
        ).toBeInTheDocument();
    });
});
