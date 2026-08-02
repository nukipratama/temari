import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { useFocusReturn } from './useFocusReturn';

let trigger: HTMLButtonElement;

beforeEach(() => {
    trigger = document.createElement('button');
    trigger.textContent = 'open';
    document.body.appendChild(trigger);
    trigger.focus();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('useFocusReturn', () => {
    it('does nothing while closed', () => {
        renderHook(() => useFocusReturn(false));
        expect(document.activeElement).toBe(trigger);
    });

    it('restores focus to the previously-focused element on close', () => {
        const { rerender } = renderHook(
            ({ open }: { open: boolean }) => useFocusReturn(open),
            {
                initialProps: { open: true },
            },
        );
        trigger.blur();
        expect(document.activeElement).not.toBe(trigger);

        rerender({ open: false });
        expect(document.activeElement).toBe(trigger);
    });

    it('restores focus on unmount', () => {
        const { unmount } = renderHook(() => useFocusReturn(true));
        trigger.blur();
        expect(document.activeElement).not.toBe(trigger);

        unmount();
        expect(document.activeElement).toBe(trigger);
    });

    it('does not restore focus when it never opened', () => {
        const { unmount } = renderHook(() => useFocusReturn(false));
        trigger.blur();
        unmount();
        expect(document.activeElement).not.toBe(trigger);
    });
});
