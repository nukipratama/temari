import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installGlobalErrorReporting, reportClientError } from './clientErrorReporter';

describe('clientErrorReporter', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(null, { status: 204 }))));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('POSTs the error payload to /client-errors', () => {
        reportClientError({ message: 'boom', stack: 'at x', url: 'https://t/aktivitas', componentStack: 'in Page' });

        expect(fetch).toHaveBeenCalledWith('/client-errors', expect.objectContaining({ method: 'POST', keepalive: true }));
        const body = JSON.parse((fetch as unknown as { mock: { calls: [string, { body: string }][] } }).mock.calls[0][1].body);
        expect(body).toMatchObject({ message: 'boom', url: 'https://t/aktivitas' });
    });

    it('dedupes identical messages within a page load', () => {
        reportClientError({ message: 'dupe-me' });
        reportClientError({ message: 'dupe-me' });

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('never throws even if fetch blows up', () => {
        vi.stubGlobal('fetch', vi.fn(() => {
            throw new Error('network down');
        }));

        expect(() => reportClientError({ message: 'safe' })).not.toThrow();
    });

    it('installs window handlers for errors and unhandled rejections', () => {
        installGlobalErrorReporting();

        window.dispatchEvent(new ErrorEvent('error', { message: 'window-error', error: new Error('window-error') }));
        window.dispatchEvent(new PromiseRejectionEvent('unhandledrejection', {
            promise: Promise.reject(new Error('rejected')).catch(() => undefined),
            reason: new Error('rejected'),
        }));

        expect(fetch).toHaveBeenCalledTimes(2);
    });
});
