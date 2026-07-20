import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'temari:riwayat:last-filter';

/**
 * Remembers the last non-empty filter query the user applied on Jejak, so a
 * later visit can offer to resume it in one tap.
 *
 * Deliberately **not** auto-applied. Landing on a silently pre-filtered list
 * makes a subset look like a history that lost runs, and the user has no reason
 * to suspect state they never set on this visit. The URL already covers
 * "come back to exactly this view" (bookmark it); this only covers "I was doing
 * something, let me pick it back up".
 *
 * Device-local on purpose (localStorage, not the server): it is a convenience
 * for this browser, not a preference worth syncing. Storage access is guarded —
 * Safari private mode throws on write, and a corrupt value must never break the
 * page.
 */
export function useLastFilter(currentQuery: Record<string, string>) {
    // Read lazily, once, before the effect below has a chance to overwrite it.
    // `readSaved` swallows a missing/blocked `window.localStorage`, so this is
    // safe outside a browser too.
    const [saved, setSaved] = useState<Record<string, string> | null>(readSaved);
    const serialisedCurrent = JSON.stringify(currentQuery);

    // Persist whatever the user is looking at now, but never persist "no filter"
    // — that would erase a remembered filter the moment they clear it, which is
    // exactly when they might want it back.
    useEffect(() => {
        const query = JSON.parse(serialisedCurrent) as Record<string, string>;
        if (Object.keys(query).length === 0) {
            return;
        }

        try {
            window.localStorage.setItem(STORAGE_KEY, serialisedCurrent);
        } catch {
            // Private mode / quota / disabled storage: resuming is a nicety.
        }
    }, [serialisedCurrent]);

    const forget = useCallback(() => {
        setSaved(null);
        try {
            window.localStorage.removeItem(STORAGE_KEY);
        } catch {
            // Nothing to do; the in-memory state is already cleared.
        }
    }, []);

    // Only offer a resume when the user is currently unfiltered and the saved
    // filter would actually change what they see.
    const resumable =
        saved !== null && Object.keys(currentQuery).length === 0 && Object.keys(saved).length > 0
            ? saved
            : null;

    return { resumable, forget };
}

function readSaved(): Record<string, string> | null {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (raw === null) {
            return null;
        }

        const parsed: unknown = JSON.parse(raw);
        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
            return null;
        }

        // Keep only string values; anything else is a corrupt or tampered entry.
        const entries = Object.entries(parsed).filter(
            (entry): entry is [string, string] => typeof entry[1] === 'string',
        );

        return entries.length > 0 ? Object.fromEntries(entries) : null;
    } catch {
        return null;
    }
}
