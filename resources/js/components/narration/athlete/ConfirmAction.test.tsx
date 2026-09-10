import { fireEvent, render, screen } from '@testing-library/react';
import { RefreshCw } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { formMock } from '@/test/setup';

import ConfirmAction from './ConfirmAction';

function renderAction(
    overrides: Partial<Parameters<typeof ConfirmAction>[0]> = {},
) {
    return render(
        <ConfirmAction
            label="retry all failed"
            icon={RefreshCw}
            question="re-arm every failed block."
            action="/devtools/narration/athletes/7/retry-failed"
            {...overrides}
        />,
    );
}

describe('ConfirmAction', () => {
    it('posts nothing until the confirm step is answered', () => {
        renderAction();

        fireEvent.click(
            screen.getByRole('button', { name: /retry all failed/i }),
        );

        expect(
            screen.getByText('re-arm every failed block.'),
        ).toBeInTheDocument();
        expect(formMock.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'confirm' }));

        expect(formMock.post).toHaveBeenCalledWith(
            '/devtools/narration/athletes/7/retry-failed',
            { preserveScroll: true },
        );
    });

    it('disarms on cancel', () => {
        renderAction();

        fireEvent.click(
            screen.getByRole('button', { name: /retry all failed/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'cancel' }));

        expect(
            screen.queryByRole('button', { name: 'confirm' }),
        ).not.toBeInTheDocument();
        expect(formMock.post).not.toHaveBeenCalled();
    });

    it('hides the trigger entirely and says why when the action cannot run', () => {
        renderAction({ disabledReason: 'budget spent for today.' });

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.getByText('budget spent for today.')).toBeInTheDocument();
    });
});
