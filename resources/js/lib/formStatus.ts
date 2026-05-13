import type { FormStatus, Tone } from '@/types/inertia';

/**
 * form_status → human label + Tone token. Mirrors
 * App\Services\Run\Story\FormStatus::label/tone on the backend.
 */

export function formStatusLabel(status: FormStatus | null): string {
    switch (status) {
        case 'fresh':
            return 'Lagi seger';
        case 'optimal':
            return 'Pas banget';
        case 'fatigued':
            return 'Mulai capek';
        case 'overreaching':
            return 'Kelewatan';
        default:
            return '—';
    }
}

export function formStatusTone(status: FormStatus | null): Tone {
    switch (status) {
        case 'fresh':
            return 'positive';
        case 'fatigued':
            return 'warning';
        case 'overreaching':
            return 'alert';
        default:
            return 'neutral';
    }
}
