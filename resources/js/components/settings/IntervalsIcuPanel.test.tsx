import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { formMock, makeUser, setMockPage } from '@/test/setup';

import IntervalsIcuPanel, {
    INTERVALS_ICU_DEMO_BLOCKED,
    type IntervalsIcuPayload,
} from './IntervalsIcuPanel';

const notConnected: IntervalsIcuPayload = {
    connected: false,
    athlete_id: null,
    broken: false,
};

const setData = vi.fn();

beforeEach(() => {
    setMockPage({ auth: { user: makeUser() } });
    setData.mockReset();
    Object.assign(formMock, {
        data: { athlete_id: '', api_key: '' },
        setData,
    });
});

function openRow() {
    fireEvent.click(screen.getByRole('button', { name: /intervals\.icu/ }));
}

describe('IntervalsIcuPanel', () => {
    it('keeps the connect form folded until the row is opened', () => {
        render(<IntervalsIcuPanel intervalsIcu={notConnected} />);

        const row = screen.getByRole('button', { name: /intervals\.icu/ });
        expect(row).toHaveAttribute('aria-expanded', 'false');
        expect(
            screen.getByText('send your next run to your watch.'),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText(/^API key/)).not.toBeInTheDocument();

        openRow();

        expect(row).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByLabelText('athlete ID')).toBeInTheDocument();
        expect(screen.getByLabelText(/^API key/)).toHaveAttribute(
            'type',
            'password',
        );
        expect(screen.getByText(/tested on Huawei/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'open intervals.icu' }),
        ).toHaveAttribute('href', 'https://intervals.icu/settings');
    });

    it('posts the ID and key and clears the key whatever the outcome', () => {
        render(<IntervalsIcuPanel intervalsIcu={notConnected} />);
        openRow();

        fireEvent.change(screen.getByLabelText(/^API key/), {
            target: { value: 'secret' },
        });
        fireEvent.click(screen.getByRole('button', { name: /connect/ }));

        expect(setData).toHaveBeenCalledWith('api_key', 'secret');
        expect(formMock.post).toHaveBeenCalledWith(
            '/settings/intervals-icu',
            expect.objectContaining({ preserveScroll: true }),
        );
        const options = formMock.post.mock.calls[0][1] as {
            onFinish: () => void;
        };
        options.onFinish();
        expect(formMock.reset).toHaveBeenCalledWith('api_key');
    });

    it('shows what intervals.icu said under the key field', () => {
        Object.assign(formMock, {
            errors: { api_key: "intervals.icu didn't accept that ID and key." },
        });
        render(<IntervalsIcuPanel intervalsIcu={notConnected} />);
        openRow();

        expect(screen.getByRole('alert')).toHaveTextContent(
            "intervals.icu didn't accept that ID and key.",
        );
        expect(screen.getByLabelText(/^API key/)).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });

    it('says it is checking while the key is verified', () => {
        Object.assign(formMock, { processing: true });
        render(<IntervalsIcuPanel intervalsIcu={notConnected} />);
        openRow();

        expect(screen.getByRole('button', { name: /checking/ })).toBeDisabled();
    });

    it('shows a healthy connection as a static row with a disconnect beneath it', () => {
        render(
            <IntervalsIcuPanel
                intervalsIcu={{
                    connected: true,
                    athlete_id: 'i123456',
                    broken: false,
                }}
            />,
        );

        expect(
            screen.getByText('connected · athlete i123456'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /intervals\.icu/ }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /disconnect/i }));

        expect(router.delete).toHaveBeenCalledWith('/settings/intervals-icu', {
            preserveScroll: true,
        });
    });

    it('offers to reconnect a broken key with the athlete ID already filled', () => {
        render(
            <IntervalsIcuPanel
                intervalsIcu={{
                    connected: true,
                    athlete_id: 'i123456',
                    broken: true,
                }}
            />,
        );

        expect(
            screen.getByText(
                'your key stopped working · reconnect to keep sending runs.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /disconnect/i }),
        ).toBeInTheDocument();

        openRow();

        expect(screen.getByLabelText(/^API key/)).toBeInTheDocument();
    });

    it('opens the demo notice instead of the form for the demo account', async () => {
        setMockPage({ auth: { user: makeUser({ is_demo: true }) } });
        render(<IntervalsIcuPanel intervalsIcu={notConnected} />);

        openRow();

        expect(
            await screen.findByText(INTERVALS_ICU_DEMO_BLOCKED.title),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText(/^API key/)).not.toBeInTheDocument();
    });
});
