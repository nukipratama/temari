import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import RaceOutcomePrompt from './RaceOutcomePrompt';

describe('RaceOutcomePrompt', () => {
    it('asks how the race went and links to the race page', () => {
        render(
            <RaceOutcomePrompt
                race={{ id: 9, name: 'Bandung Half', race_date: '2026-10-04' }}
            />,
        );

        expect(
            screen.getByText('how did Bandung Half go?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/nothing counts as a miss/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /tell temari/ }),
        ).toHaveAttribute('href', '/race');
    });

    it('names an unnamed race plainly', () => {
        render(
            <RaceOutcomePrompt
                race={{ id: 9, name: null, race_date: '2026-10-04' }}
            />,
        );

        expect(screen.getByText('how did your race go?')).toBeInTheDocument();
    });
});
