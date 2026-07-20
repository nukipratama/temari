import { afterEach, describe, expect, it, vi } from 'vitest';
import { hapticCommit, hapticTap } from './haptics';

function stubVibrate(impl: (pattern: number) => boolean) {
    const spy = vi.fn(impl);
    Object.defineProperty(navigator, 'vibrate', { value: spy, configurable: true, writable: true });

    return spy;
}

afterEach(() => {
    Reflect.deleteProperty(navigator, 'vibrate');
});

describe('haptics', () => {
    it('vibrates briefly on a tap', () => {
        const vibrate = stubVibrate(() => true);
        hapticTap();
        expect(vibrate).toHaveBeenCalledWith(10);
    });

    it('vibrates a touch longer on a commit', () => {
        const vibrate = stubVibrate(() => true);
        hapticCommit();
        expect(vibrate).toHaveBeenCalledWith(18);
    });

    // The primary target is an installed iOS PWA, where navigator.vibrate does
    // not exist. Calling must be a silent no-op, never a crash.
    it('is a no-op when the platform has no vibrate (iOS Safari)', () => {
        Reflect.deleteProperty(navigator, 'vibrate');
        expect(() => hapticTap()).not.toThrow();
    });

    it('swallows a vibrate that throws inside an embedded webview', () => {
        stubVibrate(() => {
            throw new Error('blocked by embedder');
        });
        expect(() => hapticCommit()).not.toThrow();
    });
});
