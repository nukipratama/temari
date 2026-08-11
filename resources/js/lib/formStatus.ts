import type { FormStatus, Tone } from '@/types/inertia';

// Mirrors App\Services\Run\Story\FormStatus::label/tone.

const LABELS: Record<FormStatus, string> = {
    fresh: 'Feeling Fresh',
    optimal: 'Right on Track',
    fatigued: 'Getting Tired',
    overreaching: 'Overreaching',
};

const TONES: Record<FormStatus, Tone> = {
    fresh: 'positive',
    optimal: 'neutral',
    fatigued: 'warning',
    overreaching: 'alert',
};

export function formStatusLabel(status: FormStatus | null): string {
    return status === null ? '—' : LABELS[status];
}

export function formStatusTone(status: FormStatus | null): Tone {
    return status === null ? 'neutral' : TONES[status];
}
