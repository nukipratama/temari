import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { useFocusTrap } from './useFocusTrap';

let panel: HTMLDivElement;
let first: HTMLButtonElement;
let last: HTMLButtonElement;
let outsideTrigger: HTMLButtonElement;

beforeEach(() => {
    // The element that "opened" the dialog — focus should return here on close.
    outsideTrigger = document.createElement('button');
    outsideTrigger.textContent = 'open';
    document.body.appendChild(outsideTrigger);
    outsideTrigger.focus();

    panel = document.createElement('div');
    first = document.createElement('button');
    first.textContent = 'first';
    last = document.createElement('button');
    last.textContent = 'last';
    panel.append(first, last);
    document.body.appendChild(panel);
});

afterEach(() => {
    document.body.innerHTML = '';
});

function makeRef() {
    return { current: panel };
}

function pressTab(shiftKey = false) {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true }));
}

describe('useFocusTrap', () => {
    it('moves focus to the first tabbable when opened', () => {
        renderHook(() => useFocusTrap(true, makeRef()));
        expect(document.activeElement).toBe(first);
    });

    it('does nothing when closed', () => {
        renderHook(() => useFocusTrap(false, makeRef()));
        expect(document.activeElement).toBe(outsideTrigger);
    });

    it('focuses the panel itself when it has no tabbables', () => {
        const empty = document.createElement('div');
        document.body.appendChild(empty);
        renderHook(() => useFocusTrap(true, { current: empty }));
        expect(document.activeElement).toBe(empty);
        expect(empty.getAttribute('tabindex')).toBe('-1');
    });

    it('wraps Tab from the last element back to the first', () => {
        renderHook(() => useFocusTrap(true, makeRef()));
        last.focus();
        pressTab();
        expect(document.activeElement).toBe(first);
    });

    it('wraps Shift+Tab from the first element to the last', () => {
        renderHook(() => useFocusTrap(true, makeRef()));
        first.focus();
        pressTab(true);
        expect(document.activeElement).toBe(last);
    });

    it('restores focus to the previously-focused element on close', () => {
        const { rerender } = renderHook(
            ({ open }: { open: boolean }) => useFocusTrap(open, makeRef()),
            { initialProps: { open: true } },
        );
        expect(document.activeElement).toBe(first);
        rerender({ open: false });
        expect(document.activeElement).toBe(outsideTrigger);
    });

    it('restores focus on unmount', () => {
        const { unmount } = renderHook(() => useFocusTrap(true, makeRef()));
        expect(document.activeElement).toBe(first);
        unmount();
        expect(document.activeElement).toBe(outsideTrigger);
    });
});
