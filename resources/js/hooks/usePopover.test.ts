import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { usePopover } from './usePopover';

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
    outside.focus();
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

function makeRef() {
    return { current: container };
}

describe('usePopover', () => {
    it('calls onClose on Escape', () => {
        const onClose = vi.fn();
        renderHook(() => usePopover(true, makeRef(), onClose));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('calls onClose on a pointerdown outside the container', () => {
        const onClose = vi.fn();
        renderHook(() => usePopover(true, makeRef(), onClose));

        const event = new Event('pointerdown', { bubbles: true });
        Object.defineProperty(event, 'target', { value: outside });
        document.dispatchEvent(event);

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('does NOT trap Tab inside the container', () => {
        renderHook(() => usePopover(true, makeRef(), vi.fn()));
        expect(document.activeElement).toBe(outside);
    });

    it('restores focus to the trigger on close', () => {
        const { rerender } = renderHook(
            ({ open }: { open: boolean }) => usePopover(open, makeRef(), vi.fn()),
            { initialProps: { open: true } },
        );
        inside.focus();
        expect(document.activeElement).toBe(inside);

        rerender({ open: false });

        expect(document.activeElement).toBe(outside);
    });

    it('does nothing while closed', () => {
        const onClose = vi.fn();
        renderHook(() => usePopover(false, makeRef(), onClose));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(onClose).not.toHaveBeenCalled();
    });
});
