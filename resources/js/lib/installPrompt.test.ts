import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { defined } from '@/test/defined';

import { captureInstallPrompt, useInstallPrompt } from './installPrompt';

captureInstallPrompt();

function offerInstall() {
    const prompt = vi.fn().mockResolvedValue(undefined);
    const event = Object.assign(
        new Event('beforeinstallprompt', { cancelable: true }),
        { prompt },
    );
    act(() => {
        window.dispatchEvent(event);
    });
    return { event, prompt };
}

describe('useInstallPrompt', () => {
    it('is null until the browser offers an install', () => {
        const { result } = renderHook(() => useInstallPrompt());

        expect(result.current).toBeNull();
    });

    it('holds the offer back from the browser mini-infobar and exposes it', () => {
        const { result } = renderHook(() => useInstallPrompt());

        const { event } = offerInstall();

        expect(event.defaultPrevented).toBe(true);
        expect(result.current).toBeTypeOf('function');
        act(() => {
            window.dispatchEvent(new Event('appinstalled'));
        });
    });

    it('prompts once, then drops the spent offer', async () => {
        const { result } = renderHook(() => useInstallPrompt());
        const { prompt } = offerInstall();

        await act(() => defined(result.current)());

        expect(prompt).toHaveBeenCalledOnce();
        expect(result.current).toBeNull();
    });

    it('drops the offer once the app is installed', () => {
        const { result } = renderHook(() => useInstallPrompt());
        offerInstall();

        act(() => {
            window.dispatchEvent(new Event('appinstalled'));
        });

        expect(result.current).toBeNull();
    });
});
