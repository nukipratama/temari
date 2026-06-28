import { afterEach, describe, expect, it, vi } from 'vitest';
import { csrfToken, postJson } from './http';

afterEach(() => {
    document.head.innerHTML = '';
    vi.unstubAllGlobals();
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

describe('postJson', () => {
    it('POSTs an empty JSON body to the url with the CSRF + AJAX headers', async () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'tok-xyz';
        document.head.appendChild(meta);
        const fetchMock = vi.fn().mockResolvedValue(new Response('{"ok":true}'));
        vi.stubGlobal('fetch', fetchMock);

        await postJson('/api/markers/seen');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/markers/seen');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{}');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers['X-CSRF-TOKEN']).toBe('tok-xyz');
        expect(init.headers['Accept']).toBe('application/json');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest');
    });

    it('resolves to undefined and swallows a rejected fetch (network error)', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        await expect(postJson('/api/x')).resolves.toBeUndefined();
    });
});
