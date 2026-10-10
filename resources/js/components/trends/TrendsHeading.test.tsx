import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TrendsHeading from './TrendsHeading';

describe('TrendsHeading', () => {
    it("names the page and asks Trends' question as its title", () => {
        render(<TrendsHeading />);

        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'am i getting fitter,and at what cost?',
        );
    });
});
