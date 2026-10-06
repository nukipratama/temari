import type { SharedProps } from '@/types/inertia';

import { csrfToken } from '@/lib/http';

const SAVED_ENDPOINT_KEY = 'temari-push-endpoint';
const SEEN_ON_KEY = 'temari-push-seen-on';

let inFlightSubscribe: Promise<void> | null = null;
let inFlightSeen: Promise<void> | null = null;

/** The browser can do web push at all (iOS and iPadOS additionally gate it behind a Home-Screen install). */
export function isPushSupported(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/** Running as an installed, standalone app (the only mode iOS and iPadOS deliver push in). */
export function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (window.navigator as Navigator & { standalone?: boolean })
            .standalone === true
    );
}

/** iPhone, iPad or iPod; iPadOS Safari reports itself as a touch-enabled Mac. */
export function isIos(): boolean {
    return (
        /iP(hone|ad|od)/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

/** iOS, but not Safari — these can't install a push-capable PWA, so the UI must say "open in Safari". */
export function isIosNonSafari(): boolean {
    const ua = navigator.userAgent;
    return /iP(hone|ad|od)/.test(ua) && /CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

async function send(
    url: string,
    method: string,
    body?: unknown,
): Promise<Response> {
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
export function subscribe(publicKey: string): Promise<void> {
    inFlightSubscribe ??= subscribeOnce(publicKey).finally(() => {
        inFlightSubscribe = null;
    });

    return inFlightSubscribe;
}

async function subscribeOnce(publicKey: string): Promise<void> {
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

    const response = await save(subscription);
    if (!response.ok) {
        await subscription.unsubscribe();
        throw new Error(`subscribe failed (${response.status})`);
    }
}

async function save(subscription: PushSubscription): Promise<Response> {
    const response = await send('/profile/push', 'POST', {
        ...subscription.toJSON(),
        previous_endpoint: savedEndpoint() ?? undefined,
    });
    if (response.ok) {
        rememberEndpoint(subscription.endpoint);
    }

    return response;
}

/**
 * Tell the server, at most once a day, that a signed-in athlete still has this
 * app installed, so the daily prune keeps its subscription. A 404 means the
 * server already pruned it, so the device saves the subscription again.
 */
export function reportSeen(props: Record<string, unknown>): Promise<void> {
    const user = (props.auth as SharedProps['auth'] | undefined)?.user;
    if (!user || user.is_demo || readStorage(SEEN_ON_KEY) === today()) {
        return Promise.resolve();
    }

    inFlightSeen ??= reportSeenOnce()
        .catch(() => undefined)
        .finally(() => {
            inFlightSeen = null;
        });

    return inFlightSeen;
}

async function reportSeenOnce(): Promise<void> {
    const subscription = await currentSubscription();
    if (subscription === null) {
        return;
    }

    let response = await send('/profile/push/seen', 'POST', {
        endpoint: subscription.endpoint,
    });
    if (response.status === 404) {
        response = await save(subscription);
    }
    if (response.ok) {
        writeStorage(SEEN_ON_KEY, today());
    }
}

function today(): string {
    return new Date().toDateString();
}

/** Drop the local subscription and remove it server-side. */
export async function unsubscribe(): Promise<void> {
    const subscription = await currentSubscription();
    if (subscription === null) {
        return;
    }
    const { endpoint } = subscription;
    await subscription.unsubscribe();
    await send('/profile/push', 'DELETE', { endpoint });
    rememberEndpoint(null);
}

/**
 * Drop this device's push subscription before signing out and return its
 * endpoint, so the logout can delete the row even when the browser unsubscribe fails.
 */
export async function releaseDeviceSubscription(): Promise<string | undefined> {
    let endpoint: string | undefined;
    try {
        const subscription = await currentSubscription();
        endpoint = subscription?.endpoint;
        await subscription?.unsubscribe();
        rememberEndpoint(null);
    } catch {
        endpoint ??= savedEndpoint() ?? undefined;
    }

    return endpoint;
}

/**
 * The endpoint this install last saved server-side. A re-subscribe sends it so
 * the server drops the subscription it replaces: iOS revokes a subscription
 * without telling the server, and its push service keeps accepting sends to it.
 */
function savedEndpoint(): string | null {
    return readStorage(SAVED_ENDPOINT_KEY);
}

function rememberEndpoint(endpoint: string | null): void {
    writeStorage(SAVED_ENDPOINT_KEY, endpoint);
}

function readStorage(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(key: string, value: string | null): void {
    try {
        if (value === null) {
            localStorage.removeItem(key);
        } else {
            localStorage.setItem(key, value);
        }
    } catch {
        return;
    }
}

/** Decode a base64url VAPID public key into the Uint8Array PushManager wants. */
export function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i += 1) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}
