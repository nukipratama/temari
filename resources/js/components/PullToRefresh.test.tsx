import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PullToRefresh from './PullToRefresh';

type ReloadOptions = {
    onHttpException: () => boolean | void;
    onNetworkError: () => boolean | void;
    onCancel: () => void;
    onFinish: () => void;
};

function stubMedia({ reduced = false, coarse = true } = {}) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: query.includes('coarse') ? coarse : reduced,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    );
}

async function renderShell() {
    render(
        <PullToRefresh>
            <p>page body</p>
        </PullToRefresh>,
    );
    await act(async () => {
        await vi.dynamicImportSettled();
    });
    return screen.getByTestId('pull-to-refresh-content');
}

function touch(clientY: number) {
    return { touches: [{ identifier: 1, clientX: 10, clientY }] };
}

function drag(target: Element, to: number) {
    fireEvent.touchStart(target, touch(100));
    fireEvent.touchMove(target, touch(to));
}

function release(target: Element) {
    fireEvent.touchEnd(target, { changedTouches: [] });
}

function pullAndRelease(target: Element) {
    drag(target, 220);
    release(target);
}

function reloadOptions(): ReloadOptions {
    const [options] = vi.mocked(router.reload).mock.calls.at(-1) ?? [];
    return options as unknown as ReloadOptions;
}

beforeEach(() => {
    vi.mocked(router.reload).mockClear();
    stubMedia();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('PullToRefresh', () => {
    it('renders its children with no indicator or transform at rest', async () => {
        const content = await renderShell();

        expect(screen.getByText('page body')).toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(content.style.transform).toBe('');
    });

    it('slides the content down with the pull and shows the gap indicator', async () => {
        const content = await renderShell();

        drag(content, 160);

        expect(content.style.transform).toBe('translateY(42px)');
        expect(screen.getByRole('status')).toHaveTextContent('pull to refresh');
    });

    it('reloads the page when released past the threshold and holds the gap', async () => {
        const content = await renderShell();

        pullAndRelease(content);

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('status')).toHaveTextContent('refreshing');
        expect(content.style.transform).toBe('translateY(56px)');
    });

    it('does not reload when released short of the threshold', async () => {
        const content = await renderShell();

        drag(content, 130);
        release(content);

        expect(router.reload).not.toHaveBeenCalled();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('ignores a new pull while a refresh is in flight', async () => {
        const content = await renderShell();
        pullAndRelease(content);

        pullAndRelease(content);

        expect(router.reload).toHaveBeenCalledTimes(1);
    });

    it('settles back and drops the transform when the reload finishes', async () => {
        const content = await renderShell();
        pullAndRelease(content);

        act(() => reloadOptions().onFinish());

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(content.style.transform).toBe('');
        expect(content).toHaveClass('transition-transform');
    });

    it('settles back when the reload is cancelled', async () => {
        const content = await renderShell();
        pullAndRelease(content);

        act(() => reloadOptions().onCancel());

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it.each(['onNetworkError', 'onHttpException'] as const)(
        'suppresses the default error modal on %s and shows the failure notice',
        async (handler) => {
            const content = await renderShell();
            pullAndRelease(content);

            let suppressed: unknown;
            act(() => {
                suppressed = reloadOptions()[handler]();
                reloadOptions().onFinish();
            });

            expect(suppressed).toBe(false);
            expect(screen.getByRole('status')).toHaveTextContent(
                "couldn't refresh. pull to try again.",
            );
            expect(screen.getByText('page body')).toBeInTheDocument();
            expect(content.style.transform).toBe('translateY(56px)');
        },
    );

    it('drops the failure notice after a few seconds', async () => {
        vi.useFakeTimers();
        const content = await renderShell();
        pullAndRelease(content);
        act(() => {
            reloadOptions().onNetworkError();
            reloadOptions().onFinish();
        });

        act(() => {
            vi.advanceTimersByTime(3000);
        });

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('allows another pull while the failure notice shows', async () => {
        const content = await renderShell();
        pullAndRelease(content);
        act(() => {
            reloadOptions().onNetworkError();
            reloadOptions().onFinish();
        });

        pullAndRelease(content);

        expect(router.reload).toHaveBeenCalledTimes(2);
        expect(screen.getByRole('status')).toHaveTextContent('refreshing');
    });

    it('shows only the indicator under reduced motion, without sliding the content', async () => {
        stubMedia({ reduced: true });
        const content = await renderShell();

        drag(content, 160);

        expect(screen.getByRole('status')).toHaveTextContent('pull to refresh');
        expect(content.style.transform).toBe('');

        release(content);
        fireEvent.touchStart(content, touch(100));
        fireEvent.touchMove(content, touch(220));
        release(content);

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('status')).toHaveTextContent('refreshing');
        expect(content.style.transform).toBe('');
    });

    it('never loads the gesture on a fine pointer', async () => {
        stubMedia({ coarse: false });
        const content = await renderShell();

        pullAndRelease(content);

        expect(router.reload).not.toHaveBeenCalled();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(content.style.transform).toBe('');
    });
});
