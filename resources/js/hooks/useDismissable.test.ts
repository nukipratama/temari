import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useDismissable } from './useDismissable';

let container: HTMLDivElement;
let inside: HTMLButtonElement;
let outside: HTMLButtonElement;

beforeEach(() => {
    container = document.createElement('div');
    inside = document.createElement('button');
    inside.textContent = 'inside';
    container.appendChild(inside);

    outside = document.createElement('button');
    outside.textContent = 'outside';

    document.body.append(container, outside);
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

function makeRef() {
    return { current: container };
}

describe('useDismissable', () => {
    it('calls onClose when Escape is pressed', () => {
        const onClose = vi.fn();
        renderHook(() => useDismissable(true, makeRef(), onClose));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('ignores non-Escape keys', () => {
        const onClose = vi.fn();
        renderHook(() => useDismissable(true, makeRef(), onClose));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));

        expect(onClose).not.toHaveBeenCalled();
    });

    it('calls onClose on a pointerdown outside the container', () => {
        const onClose = vi.fn();
        renderHook(() => useDismissable(true, makeRef(), onClose));

        const event = new Event('pointerdown', { bubbles: true });
        Object.defineProperty(event, 'target', { value: outside });
        document.dispatchEvent(event);

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('does NOT call onClose on a pointerdown inside the container', () => {
        const onClose = vi.fn();
        renderHook(() => useDismissable(true, makeRef(), onClose));

        const event = new Event('pointerdown', { bubbles: true });
        Object.defineProperty(event, 'target', { value: inside });
        document.dispatchEvent(event);

        expect(onClose).not.toHaveBeenCalled();
    });

    it('does nothing while closed', () => {
        const onClose = vi.fn();
        renderHook(() => useDismissable(false, makeRef(), onClose));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        const event = new Event('pointerdown', { bubbles: true });
        Object.defineProperty(event, 'target', { value: outside });
        document.dispatchEvent(event);

        expect(onClose).not.toHaveBeenCalled();
    });

    it('removes its listeners when it closes', () => {
        const onClose = vi.fn();
        const { rerender } = renderHook(
            ({ open }: { open: boolean }) => useDismissable(open, makeRef(), onClose),
            { initialProps: { open: true } },
        );

        rerender({ open: false });
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(onClose).not.toHaveBeenCalled();
    });

    it('removes its listeners on unmount', () => {
        const onClose = vi.fn();
        const removeSpy = vi.spyOn(document, 'removeEventListener');
        const { unmount } = renderHook(() => useDismissable(true, makeRef(), onClose));

        unmount();

        expect(removeSpy).toHaveBeenCalledWith('keydown', expect.any(Function));
        expect(removeSpy).toHaveBeenCalledWith('pointerdown', expect.any(Function));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(onClose).not.toHaveBeenCalled();
    });
});
