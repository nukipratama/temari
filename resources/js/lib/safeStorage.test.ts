import { afterEach, describe, expect, it, vi } from 'vitest';

import { readSession, removeSession, writeSession } from './safeStorage';

afterEach(() => {
    vi.restoreAllMocks();
    window.sessionStorage.clear();
});

describe('safeStorage', () => {
    it('round-trips a value through sessionStorage', () => {
        writeSession('k', 'v');
        expect(readSession('k')).toBe('v');
        removeSession('k');
        expect(readSession('k')).toBeNull();
    });

    it('swallows a throwing sessionStorage', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new DOMException('blocked', 'SecurityError');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('full', 'QuotaExceededError');
        });
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new DOMException('blocked', 'SecurityError');
        });

        expect(readSession('k')).toBeNull();
        expect(() => writeSession('k', 'v')).not.toThrow();
        expect(() => removeSession('k')).not.toThrow();
    });
});
