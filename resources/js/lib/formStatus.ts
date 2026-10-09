import type { FormStatus } from '@/types/inertia';

export type LoadBalance = 'fresh' | 'steady' | 'heavy';

// Mirrors App\Services\Run\Metrics\TrainingFormStatus::loadBalance().
const BALANCE: Record<FormStatus, LoadBalance> = {
    fresh: 'fresh',
    optimal: 'steady',
    fatigued: 'heavy',
    overreaching: 'heavy',
};

export function loadBalanceOf(status: FormStatus): LoadBalance {
    return BALANCE[status];
}

// Mirrors App\Services\Run\Metrics\TrainingFormStatus::loadBalance().
export function formStatusLabel(status: FormStatus | null): string {
    return status === null ? '—' : BALANCE[status];
}

const MEANING: Record<LoadBalance, string> = {
    fresh: 'your recent running load is lighter than your longer-term load.',
    steady: 'your recent running load is close to your longer-term load.',
    heavy: "your recent running load is above your longer-term load. that's normal in a build week. if you feel run down, illness, poor sleep or under-fuelling can be the cause too.",
};

export function formStatusMeaning(status: FormStatus): string {
    return MEANING[BALANCE[status]];
}

export function formStatusWord(status: FormStatus): string {
    return BALANCE[status];
}

export type FormStatusTone = 'positive' | 'neutral' | 'warning';

const TONE: Record<LoadBalance, FormStatusTone> = {
    fresh: 'positive',
    steady: 'neutral',
    heavy: 'warning',
};

export function formStatusTone(status: FormStatus): FormStatusTone {
    return TONE[BALANCE[status]];
}

/** `+18.5` / `-18.5` — a load balance value keeps its own sign, never a bare number. */
export function formatSignedForm(form: number): string {
    return form >= 0 ? `+${form.toFixed(1)}` : form.toFixed(1);
}
