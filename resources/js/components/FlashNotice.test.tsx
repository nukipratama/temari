import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import FlashNotice from './FlashNotice';

const base = { auth: { user: null }, demoLoginEnabled: false } as const;

describe('FlashNotice', () => {
    it('renders nothing when no flash is set', () => {
        setMockPage({
            ...base,
            flash: { success: null, error: null, info: null },
        });
        const { container } = render(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when the flash prop itself is absent', () => {
        setMockPage({ ...base, flash: undefined });
        const { container } = render(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });

    it('surfaces an info flash politely', () => {
        setMockPage({
            ...base,
            flash: {
                info: "The pull from Strava is paused for a bit. It'll resume automatically.",
            },
        });
        render(<FlashNotice />);
        expect(screen.getByRole('status')).toHaveTextContent(
            'The pull from Strava is paused for a bit',
        );
    });

    it('surfaces a success flash politely', () => {
        setMockPage({
            ...base,
            flash: { success: 'Your HR zones are saved.' },
        });
        render(<FlashNotice />);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Your HR zones are saved.',
        );
    });

    it('surfaces an error flash assertively', () => {
        setMockPage({
            ...base,
            flash: { error: 'Failed to pull from Strava.' },
        });
        render(<FlashNotice />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Failed to pull from Strava.',
        );
    });

    it('shows one banner only, error first, when several flashes are set at once', () => {
        setMockPage({
            ...base,
            flash: { error: 'Failed.', info: 'Paused.', success: 'Saved.' },
        });
        render(<FlashNotice />);
        expect(screen.getAllByRole('alert')).toHaveLength(1);
        expect(screen.queryByText('Paused.')).not.toBeInTheDocument();
        expect(screen.queryByText('Saved.')).not.toBeInTheDocument();
    });

    it('ignores an empty-string flash', () => {
        setMockPage({ ...base, flash: { info: '' } });
        const { container } = render(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });

    it('dismisses when the close button is clicked', () => {
        setMockPage({
            ...base,
            flash: { info: 'Turn on notifications first.' },
        });
        render(<FlashNotice />);
        fireEvent.click(screen.getByLabelText('Close'));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('stays dismissed when a partial reload replays the same flash', () => {
        setMockPage({
            ...base,
            flash: { info: 'Just sent. Give it a moment.' },
        });
        const { rerender } = render(<FlashNotice />);
        fireEvent.click(screen.getByLabelText('Close'));

        setMockPage({
            ...base,
            flash: { info: 'Just sent. Give it a moment.' },
        });
        rerender(<FlashNotice />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('re-shows for a fresh flash after a prior dismissal', () => {
        setMockPage({
            ...base,
            flash: { info: 'Turn on notifications first.' },
        });
        const { rerender } = render(<FlashNotice />);
        fireEvent.click(screen.getByLabelText('Close'));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        setMockPage({
            ...base,
            flash: { success: 'Sending you a test notification.' },
        });
        rerender(<FlashNotice />);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Sending you a test notification.',
        );
    });

    it('clears itself when the next navigation carries no flash', () => {
        setMockPage({
            ...base,
            flash: { success: 'Your zones were re-synced from Strava.' },
        });
        const { rerender, container } = render(<FlashNotice />);
        expect(screen.getByRole('status')).toBeInTheDocument();

        setMockPage({ ...base, flash: {} });
        rerender(<FlashNotice />);
        expect(container.firstChild).toBeNull();
    });
});
