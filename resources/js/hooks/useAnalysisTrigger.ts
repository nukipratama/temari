import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { csrfToken } from '@/lib/http';
import type { AnalysisPayload, AnalysisStatus, SharedProps } from '@/types/inertia';

const POLL_INITIAL_MS = 3000;
const POLL_MAX_MS = 15000;
const POLL_BACKOFF_FACTOR = 1.4;
const POLL_MAX_ATTEMPTS = 30;
const TRIGGER_DEBOUNCE_MS = 2000;

export const RATE_LIMITED_ERROR = 'rate_limited';

const MALFORMED_RESPONSE_ERROR = 'Respons tidak valid dari server';

/**
 * Minimal runtime shape check for a trigger response. The fetch body is
 * `unknown`, so we verify the two fields this hook reads (`status` string +
 * the optional numeric `retry_after_seconds`) before trusting the cast.
 */
function isAnalysisPayload(value: unknown): value is AnalysisPayload {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    if (typeof record.status !== 'string') {
        return false;
    }
    return record.retry_after_seconds == null || typeof record.retry_after_seconds === 'number';
}

interface TriggerOptions {
    onUpdate?: (next: AnalysisPayload) => void;
}

interface TriggerResult {
    status: AnalysisStatus;
    pending: boolean;
    error: string | null;
    /**
     * Server-computed cooldown countdown source of truth — synced from the
     * latest POST response (instant) AND from prop updates (after Inertia
     * partial reload). Consumers should prefer this over `payload.retry_after_seconds`
     * directly so they don't get a brief enable→disable flicker between the
     * trigger response and the reload completing.
     */
    retryAfterSeconds: number | null;
    /**
     * The client stopped polling this in-flight block after the max-attempts
     * cap without it settling. The UI should drop the "working" skeleton for a
     * quiet "reload later" affordance instead of spinning forever.
     */
    pollingRetired: boolean;
    /**
     * LLM narration is globally paused (`aiPaused`). Consumers hide their
     * trigger affordance while this is true — a POST would be refused server-side
     * and the row would just stay pending, so the button is a dead action. The
     * global {@link AiOutageBanner} carries the explanation instead.
     */
    paused: boolean;
    trigger: () => Promise<void>;
}

// Module-level refcounted poll registry. Multiple in-flight analyses sharing
// the same reload set (the 4 activity insights, the 2 briefing analyses)
// share a single interval + visibility listener instead of each spinning up
// their own — and the slot tears down once the last in-flight subscriber
// unsubscribes (i.e. all analyses settle).
interface PollSlot {
    key: string;
    refs: number;
    only: string[];
    timeout: ReturnType<typeof setTimeout> | null;
    nextDelayMs: number;
    attempts: number;
    onVisibility: () => void;
    /** Subscribers notified when the slot gives up after the max-attempts cap. */
    onRetire: Set<() => void>;
}
const pollSlots = new Map<string, PollSlot>();

function scheduleNext(slot: PollSlot): void {
    if (slot.timeout !== null || globalThis.document.hidden) return;
    slot.timeout = globalThis.setTimeout(() => {
        slot.timeout = null;
        slot.attempts += 1;
        if (slot.attempts > POLL_MAX_ATTEMPTS) {
            retireSlot(slot, true);
            return;
        }
        router.reload({ only: slot.only });
        slot.nextDelayMs = Math.min(Math.round(slot.nextDelayMs * POLL_BACKOFF_FACTOR), POLL_MAX_MS);
        scheduleNext(slot);
    }, slot.nextDelayMs);
}

function stopSlot(slot: PollSlot): void {
    if (slot.timeout === null) return;
    globalThis.clearTimeout(slot.timeout);
    slot.timeout = null;
}

// Stop the timer AND detach the visibility listener AND drop the slot from
// the registry. Used both by the last-subscriber teardown and the max-attempts
// give-up path (`gaveUp`), so we don't leak a listener for a slot that no
// longer polls. Only evict our OWN slot: a later subscribe under the same key
// may have already replaced us in the registry (FE-6 evict-race guard).
// When `gaveUp`, notify subscribers so their UI can leave the fake skeleton.
function retireSlot(slot: PollSlot, gaveUp = false): void {
    stopSlot(slot);
    globalThis.document.removeEventListener('visibilitychange', slot.onVisibility);
    if (pollSlots.get(slot.key) === slot) {
        pollSlots.delete(slot.key);
    }
    if (gaveUp) {
        slot.onRetire.forEach((cb) => cb());
    }
}

function subscribePoll(only: string[], onRetire: () => void): () => void {
    const key = only.join('|');
    let slot = pollSlots.get(key);
    if (slot === undefined) {
        // The onVisibility closure references `slot` lazily — it isn't called
        // until the listener fires, by which point `slot` is fully initialized.
        const created: PollSlot = {
            key,
            refs: 0,
            only,
            timeout: null,
            nextDelayMs: POLL_INITIAL_MS,
            attempts: 0,
            onRetire: new Set(),
            onVisibility: () => {
                if (globalThis.document.hidden) {
                    stopSlot(created);
                } else {
                    router.reload({ only: created.only });
                    scheduleNext(created);
                }
            },
        };
        slot = created;
        pollSlots.set(key, created);
        globalThis.document.addEventListener('visibilitychange', created.onVisibility);
        scheduleNext(created);
    }
    slot.refs += 1;
    slot.onRetire.add(onRetire);
    const subscribed = slot;
    return () => {
        subscribed.onRetire.delete(onRetire);
        subscribed.refs -= 1;
        if (subscribed.refs <= 0) {
            retireSlot(subscribed);
        }
    };
}

/**
 * POST `/api/analyses/{type}/{subjectId}/trigger?discriminator=...` to enqueue
 * (or re-enqueue) an analysis. Optimistically flips local status to `queued`
 * while the request is in flight; falls back to `failed` on error.
 */
export function useAnalysisTrigger(
    payload: AnalysisPayload,
    inertiaReloadProps: string[],
    options: TriggerOptions = {},
): TriggerResult {
    const { onUpdate } = options;
    const paused = usePage<SharedProps>().props.aiPaused ?? false;
    const [status, setStatus] = useState<AnalysisStatus>(payload.status);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [retryAfterSeconds, setRetryAfterSeconds] = useState<number | null>(payload.retry_after_seconds ?? null);
    const [pollingRetired, setPollingRetired] = useState(false);
    const lastTriggeredAtRef = useRef(0);

    const trigger = useCallback(async () => {
        if (pending) return;
        const now = Date.now();
        if (now - lastTriggeredAtRef.current < TRIGGER_DEBOUNCE_MS) return;
        lastTriggeredAtRef.current = now;
        setPending(true);
        setError(null);
        setStatus('queued');

        const base = `/api/analyses/${payload.type}/${payload.subject_id}/trigger`;
        const url = payload.discriminator
            ? `${base}?discriminator=${encodeURIComponent(payload.discriminator)}`
            : base;

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });

            if (!response.ok) {
                throw new Error(response.status === 429 ? RATE_LIMITED_ERROR : `Trigger failed (${response.status})`);
            }

            const body: unknown = await response.json();
            if (!isAnalysisPayload(body)) {
                throw new Error(MALFORMED_RESPONSE_ERROR);
            }
            const next = body;
            setStatus(next.status);
            setRetryAfterSeconds(next.retry_after_seconds ?? null);
            onUpdate?.(next);

            if (inertiaReloadProps.length > 0) {
                router.reload({ only: inertiaReloadProps });
            }
        } catch (err) {
            setStatus('failed');
            setError(err instanceof Error ? err.message : String(err));
        } finally {
            setPending(false);
        }
    }, [pending, payload.type, payload.subject_id, payload.discriminator, inertiaReloadProps, onUpdate]);

    useEffect(() => {
        setStatus(payload.status);
    }, [payload.status]);

    useEffect(() => {
        setRetryAfterSeconds(payload.retry_after_seconds ?? null);
    }, [payload.retry_after_seconds]);

    const reloadKey = inertiaReloadProps.join('|');
    const isInFlight = payload.status === 'queued' || payload.status === 'processing';
    useEffect(() => {
        if (!isInFlight || reloadKey === '') return;
        setPollingRetired(false);
        return subscribePoll(reloadKey.split('|'), () => setPollingRetired(true));
    }, [isInFlight, reloadKey]);

    return { status, pending, error, retryAfterSeconds, pollingRetired, paused, trigger };
}
