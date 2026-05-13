import { useRef } from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TemariFollow from './TemariFollow';

type IOCallback = (entries: Array<{ isIntersecting: boolean }>) => void;

const observers: IOCallback[] = [];

class IOStub {
    constructor(public callback: IOCallback) {
        observers.push(callback);
    }
    observe() {}
    disconnect() {}
}

beforeEach(() => {
    observers.length = 0;
    vi.stubGlobal('IntersectionObserver', IOStub);
    vi.stubGlobal('scrollTo', vi.fn());
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function Harness() {
    const ref = useRef<HTMLDivElement>(null);
    return (
        <>
            <div ref={ref} data-testid="sentinel" />
            <TemariFollow sentinelRef={ref} mood="glow" />
        </>
    );
}

describe('TemariFollow', () => {
    it('is hidden when the sentinel is in the viewport', () => {
        render(<Harness />);
        act(() => observers[0]?.([{ isIntersecting: true }]));
        expect(screen.queryByRole('button', { name: /Kembali ke briefing/ })).not.toBeInTheDocument();
    });

    it('appears when the sentinel scrolls out of the viewport', () => {
        render(<Harness />);
        act(() => observers[0]?.([{ isIntersecting: false }]));
        expect(screen.getByRole('button', { name: /Kembali ke briefing/ })).toBeInTheDocument();
    });

    it('clicking it smooth-scrolls back to the top', () => {
        render(<Harness />);
        act(() => observers[0]?.([{ isIntersecting: false }]));
        fireEvent.click(screen.getByRole('button', { name: /Kembali ke briefing/ }));
        expect(vi.mocked(globalThis.scrollTo)).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });
});
