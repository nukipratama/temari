import { useSyncExternalStore } from 'react';

export interface Toast {
    id: number;
    message: string;
}

export const TOAST_DISMISS_MS = 4000;

let current: Toast | null = null;
let nextId = 0;
let dismissTimer: ReturnType<typeof setTimeout> | undefined;
const listeners = new Set<() => void>();

function publish(toast: Toast | null): void {
    current = toast;
    listeners.forEach((listener) => listener());
}

/** Shows `message` in the shell's toast, replacing any toast still on screen. */
export function showToast(message: string): void {
    clearTimeout(dismissTimer);
    nextId += 1;
    publish({ id: nextId, message });
    dismissTimer = setTimeout(() => publish(null), TOAST_DISMISS_MS);
}

function subscribe(onChange: () => void): () => void {
    listeners.add(onChange);
    return () => listeners.delete(onChange);
}

export function useToast(): Toast | null {
    return useSyncExternalStore(
        subscribe,
        () => current,
        () => null,
    );
}
