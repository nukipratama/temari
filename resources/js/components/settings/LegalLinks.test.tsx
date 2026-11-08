import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import LegalLinks from './LegalLinks';

describe('LegalLinks', () => {
    it('links out to the three legal pages', () => {
        render(<LegalLinks />);

        const links: Array<[string, string]> = [
            ['terms of use', '/terms'],
            ['privacy policy', '/privacy'],
            ['training disclaimer', '/training-disclaimer'],
        ];
        for (const [label, href] of links) {
            expect(
                screen.getByRole('link', { name: new RegExp(label) }),
            ).toHaveAttribute('href', href);
        }
    });
});
