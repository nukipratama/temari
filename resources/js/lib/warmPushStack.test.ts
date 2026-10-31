import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const startPushStack = vi.fn<(currentComponent: () => string) => void>();

vi.mock('@/lib/pushStack', () => ({ startPushStack }));

async function freshWarm() {
    vi.resetModules();
    return (await import('./warmPushStack')).warmPushStack;
}

describe('warmPushStack', () => {
    beforeEach(() => {
        startPushStack.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('loads the push slide once the browser is idle, reading the latest page', async () => {
        const idle = vi.fn<(callback: () => void) => number>();
        vi.stubGlobal('requestIdleCallback', idle);
        const warmPushStack = await freshWarm();

        warmPushStack('History');
        warmPushStack('Plan');

        expect(idle).toHaveBeenCalledOnce();
        expect(startPushStack).not.toHaveBeenCalled();

        idle.mock.calls[0]?.[0]();
        await vi.waitFor(() => expect(startPushStack).toHaveBeenCalledOnce());
        expect(startPushStack.mock.calls[0]?.[0]()).toBe('Plan');

        warmPushStack('Inbox');
        expect(startPushStack.mock.calls[0]?.[0]()).toBe('Inbox');
    });

    it('falls back to a timer where the browser has no idle callback', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('requestIdleCallback', undefined);
        const warmPushStack = await freshWarm();

        warmPushStack('History');
        await vi.advanceTimersByTimeAsync(2000);

        expect(startPushStack).toHaveBeenCalledOnce();
    });

    it('never loads under Data Saver', async () => {
        const idle = vi.fn();
        vi.stubGlobal('requestIdleCallback', idle);
        vi.stubGlobal('navigator', { connection: { saveData: true } });
        const warmPushStack = await freshWarm();

        warmPushStack('History');

        expect(idle).not.toHaveBeenCalled();
    });
});
