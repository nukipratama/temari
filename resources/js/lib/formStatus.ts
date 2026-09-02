import type { FormStatus } from '@/types/inertia';

// Mirrors App\Services\Run\Story\FormStatus::label/tone.

const LABELS: Record<FormStatus, string> = {
    fresh: 'feeling fresh',
    optimal: 'right on track',
    fatigued: 'getting tired',
    overreaching: 'overreaching',
};

export function formStatusLabel(status: FormStatus | null): string {
    return status === null ? '—' : LABELS[status];
}
