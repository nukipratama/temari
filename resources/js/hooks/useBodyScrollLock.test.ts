import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useBodyScrollLock } from './useBodyScrollLock';

describe('useBodyScrollLock', () => {
    it('does nothing while inactive', () => {
        renderHook(() => useBodyScrollLock(false));
        expect(document.body.style.overflow).toBe('');
    });

    it('locks scroll while active and restores on unmount', () => {
        const { unmount } = renderHook(() => useBodyScrollLock(true));
        expect(document.body.style.overflow).toBe('hidden');

        unmount();
        expect(document.body.style.overflow).toBe('');
    });

    it('unlocks when the flag flips back to false', () => {
        const { rerender } = renderHook(({ active }) => useBodyScrollLock(active), {
            initialProps: { active: true },
        });
        expect(document.body.style.overflow).toBe('hidden');

        rerender({ active: false });
        expect(document.body.style.overflow).toBe('');
    });

    // The reason the count lives at module scope: a card reveal can open the
    // share modal on top of itself. Releasing the inner overlay must not unlock
    // the page while the outer one is still up.
    it('stays locked until the last overlay releases', () => {
        const outer = renderHook(() => useBodyScrollLock(true));
        const inner = renderHook(() => useBodyScrollLock(true));

        inner.unmount();
        expect(document.body.style.overflow).toBe('hidden');

        outer.unmount();
        expect(document.body.style.overflow).toBe('');
    });

    it('restores whatever overflow was set before the first lock', () => {
        document.body.style.overflow = 'scroll';

        const { unmount } = renderHook(() => useBodyScrollLock(true));
        expect(document.body.style.overflow).toBe('hidden');

        unmount();
        expect(document.body.style.overflow).toBe('scroll');

        document.body.style.overflow = '';
    });
});
