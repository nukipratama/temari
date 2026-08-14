import type { FormStatus } from '@/types/inertia';

// Mirrors App\Services\Run\Story\FormStatus::label/tone.

const LABELS: Record<FormStatus, string> = {
    fresh: 'Feeling Fresh',
    optimal: 'Right on Track',
    fatigued: 'Getting Tired',
    overreaching: 'Overreaching',
};

export function formStatusLabel(status: FormStatus | null): string {
    return status === null ? '—' : LABELS[status];
}
