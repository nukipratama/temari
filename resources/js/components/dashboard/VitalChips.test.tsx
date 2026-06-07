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

    it('uses the absolute form score as the Vibe value and signed form for Kesiapan', () => {
        render(<VitalChips briefing={briefing} load={load} />);
        // |−2.5| → "2.5"
        expect(screen.getByText('2.5')).toBeInTheDocument();
        // signed form → "-2.5"
        expect(screen.getByText('-2.5')).toBeInTheDocument();
        // recovery hours label
        expect(screen.getByText('41j')).toBeInTheDocument();
    });

    it('falls back to em-dash and the qualitative vibe label when load is null', () => {
        render(<VitalChips briefing={briefing} load={null} />);
        // Vibe value falls back to the label; Kesiapan falls back to "—".
        expect(screen.getByText('Membara')).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
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
