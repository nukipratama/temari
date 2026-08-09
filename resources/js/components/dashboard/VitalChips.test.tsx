import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    AnalysisPayload,
    BriefingResult,
    TrainingLoad,
} from '@/types/inertia';

import VitalChips from './VitalChips';

function payload(): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'x',
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: null,
    };
}

const briefing: BriefingResult = {
    vibeState: 'pumped',
    vibeLabel: 'Pumped',
    vibeEmoji: '💥',
    mascotVoice: payload(),
    featuredKartuVoice: payload(),
    featuredCardId: null,
    recoveryLabel: 'Recovery: 41h',
    recoveryTone: 'positive',
    recoveryHoursLabel: '41h',
    recoveryHours: 41,
    streakLabel: 'Ran today',
    sigilPattern: 'orct',
    accessory: null,
    mood: 'nyala',
};

const load: TrainingLoad = {
    form: -2.5,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    monotony: 1.2,
    strain: 384,
};

describe('VitalChips', () => {
    it('renders all three labels', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        expect(screen.getByText('Vibe')).toBeInTheDocument();
        expect(screen.getByText('Readiness')).toBeInTheDocument();
        expect(screen.getByText('Break')).toBeInTheDocument();
    });

    it('leads the Vibe tile with the label and a gloss sub, and signed form for Readiness', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // Vibe shows just the label (not the |form| number that duplicated
        // Readiness), with a one-line gloss on the sub-line.
        expect(screen.getByText('Pumped')).toBeInTheDocument();
        expect(screen.getByText('on fire')).toBeInTheDocument();
        expect(screen.queryByText('2.5')).not.toBeInTheDocument();
        // signed form → "-2.5"
        expect(screen.getByText('-2.5')).toBeInTheDocument();
        // recovery hours label
        expect(screen.getByText('41h')).toBeInTheDocument();
    });

    it('scales the value with a fluid clamp so it fits the narrow mobile column', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // text-stat-fluid's floor was lowered (app.css) to 19px so signed values
        // still fit the 1/3-width tile at 320px.
        expect(screen.getByText('-2.5').className).toContain('text-stat-fluid');
    });

    it('still shows the vibe label and an em-dash Readiness when load is null', () => {
        render(<VitalChips briefing={briefing} load={null} />);
        expect(screen.getByText('Pumped')).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('renders the vibe value with a word-friendly size (not the numeric stat size)', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // A vibe is a word, so it drops the big tabular numeric size for a fluid
        // word size that fits the narrow 3-up mobile tile.
        const vibe = screen.getByText('Pumped');
        expect(vibe.className).not.toContain('text-stat-fluid');
        expect(vibe.className).not.toContain('tabular-nums');
    });

    it('gives each gauge an accessible name and value via a visually-hidden <meter>', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        const vibeMeter = screen.getByRole('meter', { name: 'Vibe' });
        expect(vibeMeter).toHaveAttribute('value', '2.5');
        expect(vibeMeter).toHaveAttribute('min', '0');
        expect(vibeMeter).toHaveAttribute('max', '40');

        const kesiapanMeter = screen.getByRole('meter', { name: 'Readiness' });
        expect(kesiapanMeter).toHaveAttribute('value', '-2.5');
        expect(kesiapanMeter).toHaveAttribute('min', '-40');
        expect(kesiapanMeter).toHaveAttribute('max', '40');
    });

    // Recovery is driven by hours since the last run, not by training load, so
    // it keeps its gauge even when there is no load to compute Vibe/Readiness
    // from. Only the two load-derived rails disappear.
    it('drops only the load-derived gauges when load is null', () => {
        render(<VitalChips briefing={briefing} load={null} />);
        expect(
            screen.queryByRole('meter', { name: 'Vibe' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('meter', { name: 'Readiness' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('meter', { name: 'Break' }),
        ).toBeInTheDocument();
    });

    // All three tiles now share one structure: dot + label + explainer, value,
    // a bounded gauge with anchors, and a one-line sub. Recovery used to be the
    // odd one out — a solid full-width rail with no scale and no explainer.
    it('gives Recovery the same bounded gauge as its siblings', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        const meter = screen.getByRole('meter', { name: 'Break' });
        expect(meter).toHaveAttribute('value', '41');
        expect(meter).toHaveAttribute('min', '0');
        expect(meter).toHaveAttribute('max', '72');
    });

    it('clamps the Recovery gauge once past the 72h mark', () => {
        const longRest: BriefingResult = {
            ...briefing,
            recoveryHours: 200,
            recoveryHoursLabel: '8 days',
        };
        render(<VitalChips briefing={longRest} load={load} />);
        expect(screen.getByRole('meter', { name: 'Break' })).toHaveAttribute(
            'value',
            '72',
        );
    });

    it('gives every tile an explainer, Recovery included', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // Vibe's explainer points at the `vibe_vs_mood` entry, so its label is
        // the glossary label rather than the tile label.
        expect(
            screen.getByRole('button', { name: 'Explain Vibe vs Mood' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Explain Readiness' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Explain Break' }),
        ).toBeInTheDocument();
    });

    it('falls back to streakLabel then recoveryLabel for the Recovery chip', () => {
        const noHours: BriefingResult = {
            ...briefing,
            recoveryHoursLabel: null,
        };
        const { rerender } = render(
            <VitalChips briefing={noHours} load={load} />,
        );
        expect(screen.getByText('Ran today')).toBeInTheDocument();

        const onlyRecovery: BriefingResult = {
            ...briefing,
            recoveryHoursLabel: null,
            streakLabel: null,
        };
        rerender(<VitalChips briefing={onlyRecovery} load={load} />);
        expect(screen.getByText('Recovery: 41h')).toBeInTheDocument();
    });
});
