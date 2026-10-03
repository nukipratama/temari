import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import UserAvatar from './UserAvatar';

type Outcome = 'load' | 'error' | 'pending';

function stubImageLoading(outcome: Outcome) {
    class FakeImage {
        onload: (() => void) | null = null;
        onerror: (() => void) | null = null;
        crossOrigin = '';
        referrerPolicy = '';
        set src(_: string) {
            if (outcome === 'pending') {
                return;
            }
            queueMicrotask(() =>
                (outcome === 'load' ? this.onload : this.onerror)?.(),
            );
        }
    }
    vi.stubGlobal('Image', FakeImage);
}

describe('UserAvatar', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('shows the photo once it has loaded, without the initial', async () => {
        stubImageLoading('load');
        const { container } = render(
            <UserAvatar
                name="Ada Lovelace"
                avatarUrl="https://example.test/a.png"
            />,
        );
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1000);
        });
        expect(container.querySelector('img')).toHaveAttribute(
            'src',
            'https://example.test/a.png',
        );
        expect(screen.queryByText('A')).not.toBeInTheDocument();
    });

    it('shows the initial when the photo fails to load', async () => {
        stubImageLoading('error');
        const { container } = render(
            <UserAvatar
                name="Ada Lovelace"
                avatarUrl="https://example.test/broken.png"
            />,
        );
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1000);
        });
        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('A')).toBeInTheDocument();
    });

    it('holds the initial back while the photo is still loading, then reveals it', async () => {
        stubImageLoading('pending');
        const { container } = render(
            <UserAvatar
                name="Ada Lovelace"
                avatarUrl="https://example.test/slow.png"
            />,
        );
        expect(container.querySelector('img')).toBeNull();
        expect(screen.queryByText('A')).not.toBeInTheDocument();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1000);
        });
        expect(screen.getByText('A')).toBeInTheDocument();
    });

    it('falls back to the first letter of the name when there is no avatarUrl', () => {
        render(<UserAvatar name="Bianca" avatarUrl={null} />);
        expect(screen.getByText('B')).toBeInTheDocument();
    });

    it('uses the larger size classes by default and smaller ones for size="sm"', () => {
        const { container: md } = render(
            <UserAvatar name="Bianca" avatarUrl={null} />,
        );
        expect(md.firstElementChild!.className).toContain('h-9');

        const { container: sm } = render(
            <UserAvatar name="Bianca" avatarUrl={null} size="sm" />,
        );
        expect(sm.firstElementChild!.className).toContain('h-8');
    });
});
