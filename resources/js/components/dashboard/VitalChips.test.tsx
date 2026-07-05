import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import VitalChips from './VitalChips';
import type { AnalysisPayload, BriefingResult, TrainingLoad } from '@/types/inertia';

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
    vibeLabel: 'Membara',
    vibeEmoji: '💥',
    headline: payload(),
    suggestion: payload(),
    mascotVoice: payload(),
    featuredKartuVoice: payload(),
    featuredCardId: null,
    recoveryLabel: 'Pemulihan: 41j',
    recoveryTone: 'positive',
    recoveryHoursLabel: '41j',
    streakLabel: 'Lari hari ini',
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
        expect(screen.getByText('Kesiapan')).toBeInTheDocument();
        expect(screen.getByText('Recovery')).toBeInTheDocument();
    });

    it('leads the Vibe tile with the emoji + label and a gloss sub, and signed form for Kesiapan', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // Vibe shows the emoji + label inline (not the |form| number that
        // duplicated Kesiapan), with a one-line gloss on the sub-line.
        expect(screen.getByText('💥 Membara')).toBeInTheDocument();
        expect(screen.getByText('lagi on fire')).toBeInTheDocument();
        expect(screen.queryByText('2.5')).not.toBeInTheDocument();
        // signed form → "-2.5"
        expect(screen.getByText('-2.5')).toBeInTheDocument();
        // recovery hours label
        expect(screen.getByText('41j')).toBeInTheDocument();
    });

    it('scales the value with a fluid clamp so it fits the narrow mobile column', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // The signed form value must shrink below 40px on narrow viewports.
        expect(screen.getByText('-2.5').className).toContain('text-stat-fluid');
    });

    it('still shows the vibe emoji + label and an em-dash Kesiapan when load is null', () => {
        render(<VitalChips briefing={briefing} load={null} />);
        expect(screen.getByText('💥 Membara')).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('renders the vibe value with a word-friendly size (not the numeric stat size)', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // A vibe is a word, so it drops the big tabular numeric size for a fluid
        // word size that fits the narrow 3-up mobile tile.
        const vibe = screen.getByText('💥 Membara');
        expect(vibe.className).not.toContain('text-stat-fluid');
        expect(vibe.className).not.toContain('tabular-nums');
    });

    it('gives each gauge an accessible name and value via a visually-hidden <meter>', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        const vibeMeter = screen.getByRole('meter', { name: 'Vibe' });
        expect(vibeMeter).toHaveAttribute('value', '2.5');
        expect(vibeMeter).toHaveAttribute('min', '0');
        expect(vibeMeter).toHaveAttribute('max', '40');

        const kesiapanMeter = screen.getByRole('meter', { name: 'Kesiapan' });
        expect(kesiapanMeter).toHaveAttribute('value', '-2.5');
        expect(kesiapanMeter).toHaveAttribute('min', '-40');
        expect(kesiapanMeter).toHaveAttribute('max', '40');
    });

    it('renders no gauges when load is null', () => {
        render(<VitalChips briefing={briefing} load={null} />);
        expect(screen.queryByRole('meter')).not.toBeInTheDocument();
    });

    it('falls back to streakLabel then recoveryLabel for the Recovery chip', () => {
        const noHours: BriefingResult = { ...briefing, recoveryHoursLabel: null };
        const { rerender } = render(<VitalChips briefing={noHours} load={load} />);
        expect(screen.getByText('Lari hari ini')).toBeInTheDocument();

        const onlyRecovery: BriefingResult = { ...briefing, recoveryHoursLabel: null, streakLabel: null };
        rerender(<VitalChips briefing={onlyRecovery} load={load} />);
        expect(screen.getByText('Pemulihan: 41j')).toBeInTheDocument();
    });
});
