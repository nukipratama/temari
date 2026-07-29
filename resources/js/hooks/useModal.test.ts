import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useModal } from './useModal';

let panel: HTMLDivElement;
let first: HTMLButtonElement;
let outsideTrigger: HTMLButtonElement;

beforeEach(() => {
    outsideTrigger = document.createElement('button');
    outsideTrigger.textContent = 'open';
    document.body.appendChild(outsideTrigger);
    outsideTrigger.focus();

    panel = document.createElement('div');
    first = document.createElement('button');
    first.textContent = 'first';
    panel.appendChild(first);
    document.body.appendChild(panel);

    document.body.style.overflow = '';
});

afterEach(() => {
    document.body.innerHTML = '';
    document.body.style.overflow = '';
    vi.restoreAllMocks();
});

function makeRef() {
    return { current: panel };
}

describe('useModal', () => {
    it('calls onClose on Escape', () => {
        const onClose = vi.fn();
        renderHook(() => useModal(true, makeRef(), onClose));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('calls onClose on a pointerdown outside the panel', () => {
        const onClose = vi.fn();
        renderHook(() => useModal(true, makeRef(), onClose));

        const event = new Event('pointerdown', { bubbles: true });
        Object.defineProperty(event, 'target', { value: outsideTrigger });
        document.dispatchEvent(event);

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('traps focus inside the panel when open', () => {
        renderHook(() => useModal(true, makeRef(), vi.fn()));
        expect(document.activeElement).toBe(first);
    });

    it('locks body scroll while open', () => {
        renderHook(() => useModal(true, makeRef(), vi.fn()));
        expect(document.body.style.overflow).toBe('hidden');
    });

    it('restores focus and scroll on close', () => {
        const { rerender } = renderHook(
            ({ open }: { open: boolean }) => useModal(open, makeRef(), vi.fn()),
            { initialProps: { open: true } },
        );
        expect(document.activeElement).toBe(first);
        expect(document.body.style.overflow).toBe('hidden');

        rerender({ open: false });

        expect(document.activeElement).toBe(outsideTrigger);
        expect(document.body.style.overflow).toBe('');
    });

    it('does nothing while closed', () => {
        const onClose = vi.fn();
        renderHook(() => useModal(false, makeRef(), onClose));

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(onClose).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(outsideTrigger);
        expect(document.body.style.overflow).toBe('');
    });
});
