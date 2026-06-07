import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KataTemariCompact from './KataTemariCompact';
import type { AnalysisPayload, BriefingResult } from '@/types/inertia';

function payload(content: string): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content,
        type: 'briefing_mascot_voice',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: '2026-05-18',
    };
}

function briefingWith(content: string): BriefingResult {
    return {
        vibeState: 'pumped',
        vibeLabel: 'Membara',
        vibeEmoji: '💥',
        headline: payload('Pagi yang oke'),
        suggestion: payload('Tempo ringan.'),
        mascotVoice: payload(content),
        featuredKartuVoice: payload('Kartu keren.'),
        recoveryLabel: 'Pemulihan: 41j',
        recoveryTone: 'positive',
        recoveryHoursLabel: '41j',
        streakLabel: 'Lari hari ini',
        sigilPattern: 'orct',
        accessory: null,
        mood: 'nyala',
    };
}

describe('KataTemariCompact', () => {
    it('renders the section label and the mascot quote', () => {
        render(<KataTemariCompact briefing={briefingWith('Dua lari terakhirmu negatif-split.')} pose="observational" />);
        expect(screen.getByText('Kata Temari hari ini')).toBeInTheDocument();
        expect(screen.getByText(/negatif-split/)).toBeInTheDocument();
    });
});
