import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import AddRacePrompt from './AddRacePrompt';

describe('AddRacePrompt', () => {
    it('says what a race gives the plan and links to the race page', () => {
        render(<AddRacePrompt />);

        expect(
            screen.getByText(/add a race and the plan builds base/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: '+ add a race' }),
        ).toHaveAttribute('href', '/race');
    });
});
