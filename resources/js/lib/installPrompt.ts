import { useSyncExternalStore } from 'react';

/**
 * Chrome and Edge fire `beforeinstallprompt` once, early, whichever page is
 * open, so it is captured at boot and held until Settings offers it. iOS
 * Safari never fires it; the push explainer covers Add to Home Screen there.
 */
type InstallPromptEvent = Event & { prompt: () => Promise<unknown> };

let deferred: InstallPromptEvent | null = null;
const listeners = new Set<() => void>();

function setDeferred(event: InstallPromptEvent | null): void {
    deferred = event;
    for (const listener of listeners) {
        listener();
    }
}

export function captureInstallPrompt(): void {
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        setDeferred(event as InstallPromptEvent);
    });
    window.addEventListener('appinstalled', () => setDeferred(null));
}

async function install(): Promise<void> {
    const event = deferred;
    setDeferred(null);
    await event?.prompt();
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** The install action while the browser offers one, otherwise null. */
export function useInstallPrompt(): (() => Promise<void>) | null {
    const available = useSyncExternalStore(
        subscribe,
        () => deferred !== null,
        () => false,
    );

    return available ? install : null;
}
