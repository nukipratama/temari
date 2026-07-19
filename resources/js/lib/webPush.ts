import { csrfToken } from '@/lib/http';

/** The browser can do web push at all (all iOS browsers gate this behind a Home-Screen install). */
export function isPushSupported(): boolean {
    return typeof navigator !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;
}

/** Running as an installed, standalone app (the only mode iOS delivers push in). */
export function isStandalone(): boolean {
    return window.matchMedia('(display-mode: standalone)').matches
        || (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
}

/** iOS, but not Safari — these can't install a push-capable PWA, so the UI must say "open in Safari". */
export function isIosNonSafari(): boolean {
    const ua = navigator.userAgent;
    return /iP(hone|ad|od)/.test(ua) && /CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

async function send(url: string, method: string, body?: unknown): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

/** The live push subscription for this browser, or null. */
export async function currentSubscription(): Promise<PushSubscription | null> {
    if (!isPushSupported()) {
        return null;
    }
    const registration = await navigator.serviceWorker.getRegistration();

    return registration ? registration.pushManager.getSubscription() : null;
}

/**
 * Register the service worker, request permission (must be from a user gesture),
 * subscribe, and persist the subscription server-side. Throws 'permission-denied'
 * when the user (or the OS) blocks the prompt.
 */
export async function subscribe(publicKey: string): Promise<void> {
    const registration = await navigator.serviceWorker.register('/sw.js');
    await navigator.serviceWorker.ready;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        throw new Error('permission-denied');
    }

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        // A base64url-decoded key is always ArrayBuffer-backed; the cast just drops
        // the SharedArrayBuffer arm of BufferSource that modern lib types include.
        applicationServerKey: urlBase64ToUint8Array(publicKey) as BufferSource,
    });

    const response = await send('/profil/push', 'POST', subscription.toJSON());
    if (!response.ok) {
        await subscription.unsubscribe();
        throw new Error(`subscribe failed (${response.status})`);
    }
}

/** Drop the local subscription and remove it server-side. */
export async function unsubscribe(): Promise<void> {
    const subscription = await currentSubscription();
    if (subscription === null) {
        return;
    }
    const { endpoint } = subscription;
    await subscription.unsubscribe();
    await send('/profil/push', 'DELETE', { endpoint });
}

/** Decode a base64url VAPID public key into the Uint8Array PushManager wants. */
export function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i += 1) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}
