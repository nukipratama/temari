import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import LegalDocument from './Document';

const SECTIONS = [
    {
        heading: 'What is stored',
        paragraphs: ['Your name and your Strava athlete id.'],
    },
    {
        heading: 'Cutting access from Strava',
        paragraphs: [
            'Revoke at https://www.strava.com/settings/apps at any time.',
        ],
    },
];

function renderDocument(overrides = {}) {
    return render(
        <LegalDocument
            slug="privacy"
            title="Privacy policy"
            updated="2026-08-13"
            intro="What is held, and what leaves the server."
            sections={SECTIONS}
            {...overrides}
        />,
    );
}

describe('Legal/Document', () => {
    it('renders the title, the date and every section', () => {
        renderDocument();

        expect(
            screen.getByRole('heading', { level: 1, name: 'Privacy policy' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/last updated 2026-08-13/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { level: 2, name: 'What is stored' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Your name and your Strava athlete id.'),
        ).toBeInTheDocument();
    });

    it('turns a bare URL in the copy into a link', () => {
        renderDocument();

        const link = screen.getByRole('link', {
            name: 'https://www.strava.com/settings/apps',
        });
        expect(link).toHaveAttribute(
            'href',
            'https://www.strava.com/settings/apps',
        );
        expect(link).toHaveAttribute('rel', 'noreferrer noopener');
    });

    it('links to the other documents but not to itself', () => {
        renderDocument();

        const nav = screen.getByRole('navigation', {
            name: 'Other documents',
        });
        expect(nav).toHaveTextContent('Terms of use');
        expect(nav).toHaveTextContent('How Temari uses AI');
        expect(nav).toHaveTextContent('Training disclaimer');
        expect(nav).not.toHaveTextContent('Privacy policy');
    });

    it('drops the self-link for whichever document is showing', () => {
        renderDocument({ slug: 'terms', title: 'Terms of use' });

        const nav = screen.getByRole('navigation', {
            name: 'Other documents',
        });
        expect(nav).toHaveTextContent('Privacy policy');
        expect(nav).not.toHaveTextContent('Terms of use');
    });
});
