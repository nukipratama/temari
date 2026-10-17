import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ConnectedStep from './ConnectedStep';

describe('Onboarding/ConnectedStep', () => {
    it('greets the athlete by first name and lists what is landing', () => {
        render(<ConnectedStep firstName="Budi" onContinue={vi.fn()} />);

        expect(
            screen.getByRole('heading', { name: /you.re connected, Budi./ }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/every run Strava already has for you/),
        ).toBeInTheDocument();
    });

    it('continues on the button', () => {
        const onContinue = vi.fn();
        render(<ConnectedStep firstName="Budi" onContinue={onContinue} />);

        fireEvent.click(screen.getByRole('button', { name: 'continue' }));

        expect(onContinue).toHaveBeenCalledOnce();
    });
});
