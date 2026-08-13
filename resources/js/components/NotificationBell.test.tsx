import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import NotificationBell from './NotificationBell';

describe('NotificationBell', () => {
    it('links to the inbox with no badge when nothing is unread', () => {
        setMockPage({ unreadNotifications: 0 });
        render(<NotificationBell />);

        const link = screen.getByLabelText('Inbox');
        expect(link).toHaveAttribute('href', '/inbox');
        expect(screen.queryByText('0')).not.toBeInTheDocument();
    });

    it('treats a missing shared prop as nothing unread', () => {
        setMockPage({});
        render(<NotificationBell />);

        expect(screen.getByLabelText('Inbox')).toBeInTheDocument();
    });

    it('shows the unread count and names it for screen readers', () => {
        setMockPage({ unreadNotifications: 3 });
        render(<NotificationBell />);

        expect(screen.getByLabelText('Inbox, 3 unread')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('caps a large count at 9+ so the badge stays a dot', () => {
        setMockPage({ unreadNotifications: 42 });
        render(<NotificationBell />);

        expect(screen.getByText('9+')).toBeInTheDocument();
        expect(screen.getByLabelText('Inbox, 42 unread')).toBeInTheDocument();
    });

    it('marks itself the current page on the inbox itself', () => {
        setMockPage({ unreadNotifications: 1 }, '/inbox?page=2');
        render(<NotificationBell />);

        expect(screen.getByLabelText('Inbox, 1 unread')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('is not current on any other page', () => {
        setMockPage({ unreadNotifications: 0 }, '/plan');
        render(<NotificationBell />);

        expect(screen.getByLabelText('Inbox')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('shrinks its hit area in the compact density', () => {
        setMockPage({ unreadNotifications: 0 });
        render(<NotificationBell density="compact" />);

        expect(screen.getByLabelText('Inbox').className).toContain('h-9');
    });
});
