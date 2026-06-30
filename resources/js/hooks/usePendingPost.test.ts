import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import { usePendingPost } from './usePendingPost';

describe('usePendingPost', () => {
    it('starts with pending = false', () => {
        const [pending] = renderHook(() => usePendingPost('/foo')).result.current;
        expect(pending).toBe(false);
    });

    it('calls router.post with the url, empty body, and onStart/onFinish callbacks', () => {
        vi.mocked(router.post).mockReset();
        const { result } = renderHook(() => usePendingPost('/foo', { preserveScroll: true }));
        const [, post] = result.current;

        act(() => post());

        expect(router.post).toHaveBeenCalledWith(
            '/foo',
            {},
            expect.objectContaining({
                preserveScroll: true,
                onStart: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );
    });

    it('sets pending to true while the request is in flight', () => {
        vi.mocked(router.post).mockReset();
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
        });

        const { result } = renderHook(() => usePendingPost('/foo'));
        const [, post] = result.current;

        act(() => post());

        expect(result.current[0]).toBe(true);
    });

    it('resets pending to false when the request finishes', () => {
        vi.mocked(router.post).mockReset();
        let storedFinish: (() => void) | undefined;
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onStart?.({} as never);
            storedFinish = () => options?.onFinish?.({} as never);
        });

        const { result } = renderHook(() => usePendingPost('/foo'));
        act(() => result.current[1]());
        expect(result.current[0]).toBe(true);

        act(() => storedFinish?.());
        expect(result.current[0]).toBe(false);
    });
});
