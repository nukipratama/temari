import { afterEach, describe, expect, it, vi } from 'vitest';

import { back } from './overlayHistory';

function stallEventLoop(ms: number) {
    setImmediate(() => {
        const until = Date.now() + ms;
        while (Date.now() < until);
    });
}

describe('overlayHistory test helpers', () => {
    const onPopState = vi.fn(() => window.history.back());

    afterEach(() => {
        window.removeEventListener('popstate', onPopState);
    });

    it('waits out a Back that triggers another Back, even across a stalled event loop', async () => {
        window.history.pushState({ entry: 1 }, '');
        window.history.pushState({ entry: 2 }, '');
        window.history.pushState({ entry: 3 }, '');
        window.addEventListener('popstate', onPopState, { once: true });

        stallEventLoop(40);
        await back();

        expect(onPopState).toHaveBeenCalledOnce();
        expect(window.history.state).toEqual({ entry: 1 });
    });
});
