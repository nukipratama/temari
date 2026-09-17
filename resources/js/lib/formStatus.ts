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

// A one-line plain-language gloss of what the form status means, for the
// vs-last-week comparison card — the jargon-accessibility rule for
// training-load terms (see docs/voice-and-tone.md).
const MEANING: Record<FormStatus, string> = {
    fresh: 'the last week has been lighter than your six-week average. nothing is sore.',
    optimal:
        "the last week landed right around your six-week average — nothing's piling up.",
    fatigued:
        'the last week has been heavier than your six-week average. legs are carrying it.',
    overreaching:
        'the last week has piled well past your six-week average. this is the kind of load that catches up with you.',
};

export function formStatusMeaning(status: FormStatus): string {
    return MEANING[status];
}

// The short word a chip renders, distinct from formStatusLabel's fuller
// sentence-fragment ("getting tired") — a pill wants one word.
const WORD: Record<FormStatus, string> = {
    fresh: 'fresh',
    optimal: 'balanced',
    fatigued: 'tired',
    overreaching: 'overreaching',
};

export function formStatusWord(status: FormStatus): string {
    return WORD[status];
}

export type FormStatusTone = 'positive' | 'neutral' | 'warning';

const TONE: Record<FormStatus, FormStatusTone> = {
    fresh: 'positive',
    optimal: 'neutral',
    fatigued: 'warning',
    overreaching: 'warning',
};

export function formStatusTone(status: FormStatus): FormStatusTone {
    return TONE[status];
}

/** `+18.5` / `-18.5` — a form value keeps its own sign, never a bare number. */
export function formatSignedForm(form: number): string {
    return form >= 0 ? `+${form.toFixed(1)}` : form.toFixed(1);
}
