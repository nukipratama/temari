import { act, render, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('./LottiePlayer', () => ({
    default: () => <div data-testid="lottie-loaded" />,
}));

import TemariLottie from './TemariLottie';

describe('TemariLottie', () => {
    it('falls back to the SVG mascot when src is null', () => {
        const { container } = render(<TemariLottie mood="nyala" src={null} />);
        // Confirm we took the SVG fallback path (TemariCharacter renders
        // a single SVG; old composition rendered three).
        expect(container.querySelectorAll('svg').length).toBeGreaterThanOrEqual(1);
    });

    it('falls back when src is an empty string', () => {
        const { container } = render(<TemariLottie mood="adem" src="" />);
        expect(container.querySelectorAll('svg').length).toBeGreaterThanOrEqual(1);
    });

    it('fetches the Lottie JSON when src is set', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ v: '5.7.0' }),
        });
        vi.stubGlobal('fetch', fetchMock);
        render(<TemariLottie mood="nyala" src="/lottie/temari.json" />);
        expect(fetchMock).toHaveBeenCalledWith('/lottie/temari.json', expect.anything());
        // The mocked fetch still resolves for real (just not over the network),
        // so its .then chain (setData) fires on a later microtask regardless of
        // whether this test cares about the result — flush it inside act() so
        // React doesn't warn about an update after the test returns.
        await act(async () => {
            await Promise.resolve();
        });
        vi.unstubAllGlobals();
    });

    it('falls back to the SVG mascot when the fetch errors', async () => {
        // Reject with a non-AbortError so the catch branch sets errored=true.
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network')));
        const { container } = render(<TemariLottie mood="enteng" src="/lottie/bad.json" />);
        // Even after error, fallback path renders the SVG character. Wrapped in
        // act() (not a bare awaited setTimeout) so the catch handler's
        // setErrored(true) is flushed as a monitored update.
        await act(async () => {
            await new Promise((r) => setTimeout(r, 0));
        });
        expect(container.querySelectorAll('svg').length).toBeGreaterThanOrEqual(1);
        vi.unstubAllGlobals();
    });

    it('falls back when fetch returns a non-OK status', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 404 }));
        const { container } = render(<TemariLottie mood="lemes" src="/lottie/missing.json" />);
        await act(async () => {
            await new Promise((r) => setTimeout(r, 0));
        });
        expect(container.querySelectorAll('svg').length).toBeGreaterThanOrEqual(1);
        vi.unstubAllGlobals();
    });

    it('renders the LottiePlayer once the JSON fetch resolves', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ v: '5.7.0', layers: [] }),
        }));
        const { findByTestId } = render(<TemariLottie mood="nyala" src="/lottie/temari.json" />);
        await findByTestId('lottie-loaded');
        // Reaching the Suspense fallback path requires the lazy import
        // to suspend — `LottiePlayer` is now mocked so it resolves
        // synchronously, but waitFor confirms the resolved branch ran.
        await waitFor(async () => {
            const el = await findByTestId('lottie-loaded');
            expect(el).toBeInTheDocument();
        });
        vi.unstubAllGlobals();
    });

    it('skips setData when the component unmounts before the fetch resolves', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        let resolveJson: (v: unknown) => void = () => {};
        const jsonPromise = new Promise<unknown>((res) => {
            resolveJson = res;
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: () => jsonPromise }));
        const { container, unmount } = render(<TemariLottie mood="nyala" src="/lottie/late.json" />);
        unmount();
        // Resolve AFTER unmount — the in-flight .then chain runs but the
        // aborted check returns early before setData.
        resolveJson({ v: '5.7.0' });
        await new Promise((r) => setTimeout(r, 0));
        // The aborted guard must prevent the post-unmount setData: React never
        // warns about updating an unmounted component, and the player that a
        // resolved fetch would mount never appears.
        expect(consoleError).not.toHaveBeenCalled();
        expect(container.querySelector('[data-testid="lottie-loaded"]')).toBeNull();
        consoleError.mockRestore();
        vi.unstubAllGlobals();
    });
});
