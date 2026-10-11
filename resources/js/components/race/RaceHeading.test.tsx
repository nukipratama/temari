import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import RaceHeading from './RaceHeading';

describe('RaceHeading', () => {
    it('titles the page and links back to the plan', () => {
        render(<RaceHeading />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'your race.',
        );
        expect(screen.getByRole('link', { name: 'plan' })).toHaveAttribute(
            'href',
            '/plan',
        );
    });
});
