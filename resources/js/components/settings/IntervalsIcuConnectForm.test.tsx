import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { defined } from '@/test/defined';
import { formMock } from '@/test/setup';

import IntervalsIcuConnectForm from './IntervalsIcuConnectForm';

const setData = vi.fn();

beforeEach(() => {
    setData.mockReset();
    Object.assign(formMock, {
        data: { athlete_id: '', api_key: '' },
        setData,
    });
});

describe('IntervalsIcuConnectForm', () => {
    it('asks for the athlete ID and a hidden key, and says where to find them', () => {
        render(<IntervalsIcuConnectForm athleteId="" onConnected={vi.fn()} />);

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
        render(<IntervalsIcuConnectForm athleteId="" onConnected={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/^API key/), {
            target: { value: 'secret' },
        });
        fireEvent.click(screen.getByRole('button', { name: /connect/ }));

        expect(setData).toHaveBeenCalledWith('api_key', 'secret');
        expect(formMock.post).toHaveBeenCalledWith(
            '/settings/intervals-icu',
            expect.objectContaining({ preserveScroll: true }),
        );
        const options = defined(formMock.post.mock.calls[0])[1] as {
            onFinish: () => void;
        };
        options.onFinish();
        expect(formMock.reset).toHaveBeenCalledWith('api_key');
    });

    it('shows what intervals.icu said under the key field', () => {
        Object.assign(formMock, {
            errors: { api_key: "intervals.icu didn't accept that ID and key." },
        });
        render(<IntervalsIcuConnectForm athleteId="" onConnected={vi.fn()} />);

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
        render(<IntervalsIcuConnectForm athleteId="" onConnected={vi.fn()} />);

        expect(screen.getByRole('button', { name: /checking/ })).toBeDisabled();
    });

    it('folds away once intervals.icu accepts the key', () => {
        const onConnected = vi.fn();
        render(
            <IntervalsIcuConnectForm
                athleteId="i1"
                onConnected={onConnected}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /connect/ }));

        const options = defined(formMock.post.mock.calls[0])[1] as {
            onSuccess: () => void;
        };
        options.onSuccess();
        expect(onConnected).toHaveBeenCalled();
    });
});
