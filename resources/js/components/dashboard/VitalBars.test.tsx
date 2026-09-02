import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { BriefingResult, TrainingLoad } from '@/types/inertia';

import VitalBars from './VitalBars';

const briefing: BriefingResult = {
    vibeState: 'steady',
    vibeLabel: 'Steady',
    vibeEmoji: '🙂',
    mascotVoice: {
        id: 1,
        status: 'done',
        content: 'x',
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-06-12',
    },
    recoveryLabel: 'Recovery: 14h',
    recoveryTone: 'warning',
    recoveryHoursLabel: '14h',
    recoveryHours: 14,
    streakLabel: null,
    sigilPattern: 'orct',
    accessory: null,
    mood: 'easy',
};

const load: TrainingLoad = {
    form: 8,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 34,
    weekly_trimp: 312,
    monotony: 1.2,
    strain: 384,
};

describe('VitalBars', () => {
    it("renders the prototype's three rows with their values and glosses", () => {
        render(<VitalBars briefing={briefing} load={load} />);

        expect(screen.getByText('Steady')).toBeInTheDocument();
        expect(screen.getByText('holding rhythm')).toBeInTheDocument();
        expect(screen.getByText('+8.0')).toBeInTheDocument();
        expect(screen.getByText('right on track')).toBeInTheDocument();
        expect(screen.getByText('14h')).toBeInTheDocument();
    });

    it('gives each row a bounded meter so the rail has a value screen readers can read', () => {
        render(<VitalBars briefing={briefing} load={load} />);

        const meters = screen.getAllByRole('meter');
        expect(meters).toHaveLength(3);
        // Readiness: +8 within ±40 sits just past the midpoint.
        expect(meters[1]).toHaveAttribute('aria-valuenow', '60');
    });

    it('tones a non-positive recovery as the watch state', () => {
        render(<VitalBars briefing={briefing} load={load} />);

        expect(screen.getByText('14h')).toHaveClass('text-citrus-ink');
    });

    it('tones a fatigued readiness as the watch state', () => {
        render(
            <VitalBars
                briefing={briefing}
                load={{ ...load, form: -22, form_status: 'fatigued' }}
            />,
        );

        expect(screen.getByText('-22.0')).toHaveClass('text-citrus-ink');
    });

    it('reads the readiness value as unknown when no load has been computed', () => {
        render(<VitalBars briefing={briefing} load={null} />);

        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.getAllByRole('meter')[1]).toHaveAttribute(
            'aria-valuenow',
            '0',
        );
    });

    it('falls back through the recovery labels when no hours figure exists', () => {
        render(
            <VitalBars
                briefing={{
                    ...briefing,
                    recoveryHoursLabel: null,
                    recoveryHours: null,
                    streakLabel: 'Ran today',
                }}
                load={load}
            />,
        );

        expect(screen.getByText('Ran today')).toBeInTheDocument();
    });
});
