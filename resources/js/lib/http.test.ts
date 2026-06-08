import { afterEach, describe, expect, it } from 'vitest';
import { csrfToken } from './http';

afterEach(() => {
    document.head.innerHTML = '';
});

describe('csrfToken', () => {
    it('returns the content of the csrf-token meta tag when present', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'tok-abc-123';
        document.head.appendChild(meta);

        expect(csrfToken()).toBe('tok-abc-123');
    });

    it('returns an empty string when the meta tag is missing', () => {
        expect(csrfToken()).toBe('');
    });

    it('returns an empty string when the meta tag has no content attribute', () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        document.head.appendChild(meta);

        expect(csrfToken()).toBe('');
    });
});
