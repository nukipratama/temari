import { render, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SidebarProvider, useSidebar } from './SidebarContext';
import type { ReactNode } from 'react';

const wrap = ({ children }: { children: ReactNode }) => <SidebarProvider>{children}</SidebarProvider>;

describe('SidebarContext', () => {
    it('exposes dialogRef + open + close from useSidebar', () => {
        const { result } = renderHook(() => useSidebar(), { wrapper: wrap });
        expect(result.current.dialogRef.current).toBeNull();
        expect(typeof result.current.open).toBe('function');
        expect(typeof result.current.close).toBe('function');
    });

    it('open() calls showModal on the dialog ref', () => {
        const { result } = renderHook(() => useSidebar(), { wrapper: wrap });
        const showModal = vi.fn();
        // Stub a dialog element shape — jsdom's <dialog> may not implement
        // showModal/close in all envs. The wiring is all we're verifying.
        result.current.dialogRef.current = { showModal, close: vi.fn() } as unknown as HTMLDialogElement;
        result.current.open();
        expect(showModal).toHaveBeenCalledOnce();
    });

    it('close() calls close on the dialog ref', () => {
        const { result } = renderHook(() => useSidebar(), { wrapper: wrap });
        const close = vi.fn();
        result.current.dialogRef.current = { showModal: vi.fn(), close } as unknown as HTMLDialogElement;
        result.current.close();
        expect(close).toHaveBeenCalledOnce();
    });

    it('open/close are no-ops when ref is null', () => {
        const { result } = renderHook(() => useSidebar(), { wrapper: wrap });
        expect(() => result.current.open()).not.toThrow();
        expect(() => result.current.close()).not.toThrow();
    });

    it('useSidebar throws when called outside SidebarProvider', () => {
        // Suppress React's error log noise for this one expected throw.
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
        expect(() => render(<HookProbe />)).toThrow(/useSidebar must be used inside/);
        spy.mockRestore();
    });
});

function HookProbe() {
    useSidebar();
    return null;
}
