import { router } from '@inertiajs/react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type { InboxItem, PaginatedResponse } from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import Inbox from './Inbox';

const item = (overrides: Partial<InboxItem> = {}): InboxItem => ({
    id: 1,
    kind: 'post_run',
    title: 'Your run is in',
    body: 'nice and steady out there.',
    created_at: '2026-08-13T07:30:00+07:00',
    read_at: null,
    url: null,
    run_card_id: null,
    rarity: null,
    unlock: null,
    ...overrides,
});

const page = (
    data: InboxItem[],
    overrides: Partial<PaginatedResponse<InboxItem>> = {},
): PaginatedResponse<InboxItem> => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: data.length,
    links: [],
    ...overrides,
});

function okFetch() {
    const fetchMock = vi.fn(() =>
        Promise.resolve(new Response('{}', { status: 200 })),
    );
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

beforeEach(() => setMockPage({ unreadNotifications: 1 }, '/inbox'));

describe('Inbox', () => {
    it('shows a decent empty state rather than a bare list', () => {
        render(<Inbox notifications={page([])} focusId={null} />);

        expect(screen.getByText('Nothing here yet.')).toBeInTheDocument();
        expect(screen.getByText(/lands here on its own/)).toBeInTheDocument();
    });

    it('lists the rows it was given', () => {
        render(
            <Inbox
                notifications={page([
                    item(),
                    item({ id: 2, kind: 'unlock', title: 'Unlocked: Aura' }),
                ])}
                focusId={null}
            />,
        );

        expect(screen.getByText('Your run is in')).toBeInTheDocument();
        expect(screen.getByText('Unlocked: Aura')).toBeInTheDocument();
    });

    it('counts the unread rows on this page in the eyebrow', () => {
        render(
            <Inbox
                notifications={page([
                    item(),
                    item({ id: 2, read_at: '2026-08-13T08:00:00+07:00' }),
                ])}
                focusId={null}
            />,
        );

        expect(
            screen.getByText('Inbox · 1 unread on this page'),
        ).toBeInTheDocument();
    });

    it('re-arms the real reveal through the card replay endpoint', async () => {
        const fetchMock = okFetch();
        render(
            <Inbox
                notifications={page([item({ run_card_id: 77 })])}
                focusId={null}
            />,
        );

        await userEvent.click(screen.getByText('Replay Reveal'));

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/cards/77/replay',
            expect.objectContaining({ method: 'POST' }),
        );
        await waitFor(() =>
            expect(router.reload).toHaveBeenCalledWith({
                only: ['pendingReveal'],
            }),
        );
    });

    it('replays an unlock through the same takeover that granted it', async () => {
        okFetch();
        render(
            <Inbox
                notifications={page([
                    item({
                        id: 5,
                        kind: 'unlock',
                        title: 'Unlocked: Legendary headband',
                        unlock: {
                            unlock_key: 'accessory.headband_legendary',
                            name: 'Legendary headband',
                            icon: 'mdi:hanger',
                            is_major: true,
                        },
                    }),
                ])}
                focusId={null}
            />,
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        await userEvent.click(screen.getByText('Replay Unlock'));

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('Legendary headband');
    });

    it('replays a minor unlock as the same takeover, not a lesser summary', async () => {
        okFetch();
        render(
            <Inbox
                notifications={page([
                    item({
                        id: 6,
                        kind: 'unlock',
                        unlock: {
                            unlock_key: 'accessory.medal_first',
                            name: 'First medal',
                            icon: 'mdi:medal',
                            is_major: false,
                        },
                    }),
                ])}
                focusId={null}
            />,
        );

        await userEvent.click(screen.getByText('Replay Unlock'));

        expect(await screen.findByRole('dialog')).toHaveTextContent(
            'First medal',
        );
    });

    it('marks a row read on replay and refreshes the bell count', async () => {
        const fetchMock = okFetch();
        render(
            <Inbox
                notifications={page([item({ id: 3, run_card_id: 12 })])}
                focusId={null}
            />,
        );

        expect(screen.getByLabelText('Unread')).toBeInTheDocument();

        await userEvent.click(screen.getByText('Replay Reveal'));

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/notifications/3/read',
            expect.objectContaining({ method: 'POST' }),
        );
        await waitFor(() =>
            expect(screen.queryByLabelText('Unread')).not.toBeInTheDocument(),
        );
        await waitFor(() =>
            expect(router.reload).toHaveBeenCalledWith({
                only: ['unreadNotifications'],
            }),
        );
    });

    it('marks a row read when its deep link is opened', async () => {
        const fetchMock = okFetch();
        render(
            <Inbox
                notifications={page([item({ id: 4, url: '/activities/42' })])}
                focusId={null}
            />,
        );

        await userEvent.click(screen.getByText('Open'));

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/notifications/4/read',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('never re-marks a row that already arrived read', async () => {
        const fetchMock = okFetch();
        render(
            <Inbox
                notifications={page([
                    item({
                        id: 8,
                        url: '/activities/42',
                        read_at: '2026-08-13T08:00:00+07:00',
                    }),
                ])}
                focusId={null}
            />,
        );

        await userEvent.click(screen.getByText('Open'));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('scrolls to the deep-linked row and reads it', async () => {
        const fetchMock = okFetch();
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;

        render(<Inbox notifications={page([item({ id: 9 })])} focusId={9} />);

        expect(scrollIntoView).toHaveBeenCalled();
        await waitFor(() =>
            expect(fetchMock).toHaveBeenCalledWith(
                '/api/notifications/9/read',
                expect.objectContaining({ method: 'POST' }),
            ),
        );
    });

    it('ignores a deep-link target that is not on this page', () => {
        const fetchMock = okFetch();
        render(<Inbox notifications={page([item({ id: 9 })])} focusId={999} />);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('groups rows into Today / This Week / Earlier sections', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 19, 12, 0, 0)); // Wed 19 Aug 2026

        render(
            <Inbox
                notifications={page([
                    item({
                        id: 1,
                        title: 'Today row',
                        created_at: new Date(2026, 7, 19, 8).toISOString(),
                    }),
                    item({
                        id: 2,
                        title: 'This week row',
                        created_at: new Date(2026, 7, 17, 8).toISOString(),
                    }),
                    item({
                        id: 3,
                        title: 'Earlier row',
                        created_at: new Date(2026, 7, 1, 8).toISOString(),
                    }),
                ])}
                focusId={null}
            />,
        );

        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('This Week')).toBeInTheDocument();
        expect(screen.getByText('Earlier')).toBeInTheDocument();

        vi.useRealTimers();
    });

    it('offers page links only once there is more than one page', () => {
        const { rerender } = render(
            <Inbox notifications={page([item()])} focusId={null} />,
        );
        expect(screen.queryByText('Older')).not.toBeInTheDocument();

        rerender(
            <Inbox
                notifications={page([item()], {
                    current_page: 2,
                    last_page: 3,
                })}
                focusId={null}
            />,
        );

        expect(screen.getByText('Newer')).toHaveAttribute(
            'href',
            '/inbox?page=1',
        );
        expect(screen.getByText('Older')).toHaveAttribute(
            'href',
            '/inbox?page=3',
        );
        expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
    });
});
