import { router } from '@inertiajs/react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type { InboxItem } from '@/types/inertia';

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
    distance_m: null,
    moving_time_s: null,
    ...overrides,
});

function renderInbox(
    notifications: InboxItem[],
    props: { shown?: number; hasOlder?: boolean; focusId?: number | null } = {},
) {
    return render(
        <Inbox
            notifications={notifications}
            shown={props.shown ?? 20}
            hasOlder={props.hasOlder ?? false}
            focusId={props.focusId ?? null}
        />,
    );
}

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
        renderInbox([]);

        expect(screen.getByText('nothing here yet.')).toBeInTheDocument();
        expect(screen.getByText(/lands here on its own/)).toBeInTheDocument();
    });

    it('lists the rows it was given', () => {
        renderInbox([
            item(),
            item({ id: 2, kind: 'unlock', title: 'Unlocked: Aura' }),
        ]);

        expect(screen.getByText('Your run is in')).toBeInTheDocument();
        expect(screen.getByText('Unlocked: Aura')).toBeInTheDocument();
    });

    it('counts every unread row account-wide in the eyebrow, not just the window', () => {
        setMockPage({ unreadNotifications: 7 }, '/inbox');

        renderInbox([item()]);

        expect(screen.getByText('Inbox · 7 unread')).toBeInTheDocument();
    });

    it('flattens the eyebrow when nothing is unread', () => {
        setMockPage({ unreadNotifications: 0 }, '/inbox');

        renderInbox([item({ read_at: '2026-08-13T08:00:00+07:00' })]);

        expect(screen.getByText('Inbox')).toBeInTheDocument();
    });

    it('marks a row read when its deep link is opened and refreshes the bell count', async () => {
        const fetchMock = okFetch();
        renderInbox([item({ id: 4, url: '/activities/42' })]);

        expect(screen.getByLabelText('Unread')).toBeInTheDocument();

        await userEvent.click(screen.getByText('Open'));

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/notifications/4/read',
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

    it('never re-marks a row that already arrived read', async () => {
        const fetchMock = okFetch();
        renderInbox([
            item({
                id: 8,
                url: '/activities/42',
                read_at: '2026-08-13T08:00:00+07:00',
            }),
        ]);

        await userEvent.click(screen.getByText('Open'));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('scrolls to the deep-linked row and reads it', async () => {
        const fetchMock = okFetch();
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;

        renderInbox([item({ id: 9 })], { focusId: 9 });

        expect(scrollIntoView).toHaveBeenCalled();
        await waitFor(() =>
            expect(fetchMock).toHaveBeenCalledWith(
                '/api/notifications/9/read',
                expect.objectContaining({ method: 'POST' }),
            ),
        );
    });

    it('ignores a deep-link target that is not in the window', () => {
        const fetchMock = okFetch();
        renderInbox([item({ id: 9 })], { focusId: 999 });

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('groups rows into Today / This Week / Earlier sections', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 19, 12, 0, 0)); // Wed 19 Aug 2026

        renderInbox([
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
        ]);

        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('This Week')).toBeInTheDocument();
        expect(screen.getByText('Earlier')).toBeInTheDocument();

        vi.useRealTimers();
    });

    it('asks the server for a wider window when older rows exist', () => {
        renderInbox([item()], { shown: 20, hasOlder: true });

        expect(
            screen.getByRole('link', { name: /Load older/ }),
        ).toHaveAttribute('href', '/inbox?shown=40');
    });

    it('hides load older once the history is exhausted', () => {
        renderInbox([item()], { shown: 40, hasOlder: false });

        expect(
            screen.queryByRole('link', { name: /Load older/ }),
        ).not.toBeInTheDocument();
    });
});
