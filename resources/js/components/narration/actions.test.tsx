import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { formMock } from '@/test/setup';

import { RecoverAllButton, RetryFailedButton } from './actions';

describe('RecoverAllButton', () => {
    it('posts the one-shot recovery', () => {
        render(<RecoverAllButton />);

        fireEvent.click(screen.getByRole('button', { name: 'recover all' }));

        expect(formMock.post).toHaveBeenCalledWith(
            '/devtools/narration/recover',
            expect.anything(),
        );
    });

    it('disables the button while it is in flight', () => {
        formMock.processing = true;
        render(<RecoverAllButton />);

        expect(
            screen.getByRole('button', { name: 'recover all' }),
        ).toBeDisabled();
    });
});

describe('RetryFailedButton', () => {
    it('posts the per-athlete retry', () => {
        render(<RetryFailedButton userId={7} />);

        fireEvent.click(screen.getByRole('button', { name: 'retry failed' }));

        expect(formMock.post).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7/retry-failed',
            expect.anything(),
        );
    });

    it('disables the button while it is in flight', () => {
        formMock.processing = true;
        render(<RetryFailedButton userId={7} />);

        expect(
            screen.getByRole('button', { name: 'retry failed' }),
        ).toBeDisabled();
    });
});
