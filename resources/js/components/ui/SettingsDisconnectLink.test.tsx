import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import SettingsDisconnectLink from './SettingsDisconnectLink';

describe('SettingsDisconnectLink', () => {
    it('renders the disconnect label and fires onClick', async () => {
        const onClick = vi.fn();
        render(<SettingsDisconnectLink onClick={onClick} />);

        await userEvent.click(
            screen.getByRole('button', { name: /Disconnect/ }),
        );

        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('disables the button when disabled is passed', () => {
        render(<SettingsDisconnectLink onClick={vi.fn()} disabled />);

        expect(
            screen.getByRole('button', { name: /Disconnect/ }),
        ).toBeDisabled();
    });
});
