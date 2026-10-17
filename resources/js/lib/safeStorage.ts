export function readSession(key: string): string | null {
    try {
        return window.sessionStorage.getItem(key);
    } catch {
        return null;
    }
}

export function writeSession(key: string, value: string): void {
    try {
        window.sessionStorage.setItem(key, value);
    } catch {
        // Blocked or full storage: the value just isn't remembered.
    }
}

export function removeSession(key: string): void {
    try {
        window.sessionStorage.removeItem(key);
    } catch {
        // Blocked storage: nothing to remove.
    }
}
