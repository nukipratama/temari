import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import LegalDocument from './Document';

const SECTIONS = [
    {
        heading: 'What is stored',
        paragraphs: ['Your name and your Strava athlete id.'],
    },
    {
        id: 'notes',
        heading: 'How Temari writes your notes',
        paragraphs: ['Each note is written once and stored.'],
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
            title="privacy policy"
            updated="2026-08-13"
            intro="What is held, and what leaves the server."
            summary={[
                'Only you see your runs.',
                'No ads, no trackers.',
                'Delete your account from Settings anytime.',
            ]}
            sections={SECTIONS}
            {...overrides}
        />,
    );
}

describe('Legal/Document', () => {
    it('renders the title, the date and every section', () => {
        renderDocument();

        expect(
            screen.getByRole('heading', { level: 1, name: 'privacy policy' }),
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

    it('opens with the short version before the full sections', () => {
        renderDocument();

        const summary = screen.getByRole('region', {
            name: 'the short version',
        });
        expect(summary).toHaveTextContent('Only you see your runs.');
        expect(summary).toHaveTextContent('No ads, no trackers.');
    });

    it('anchors a section by its id so the old AI-use link lands on it', () => {
        renderDocument();

        expect(
            screen
                .getByRole('heading', {
                    level: 2,
                    name: 'How Temari writes your notes',
                })
                .closest('section'),
        ).toHaveAttribute('id', 'notes');
    });

    it('spaces each section rule evenly above and below', () => {
        renderDocument();

        const sections = SECTIONS.map((section) =>
            screen
                .getByRole('heading', { level: 2, name: section.heading })
                .closest('section'),
        );
        const sectionList = sections[0]?.parentElement;

        expect(sectionList).toHaveClass(
            '[&>*:not(:last-child)]:pb-10',
            '[&>*:not(:first-child)]:pt-10',
        );
        expect(sectionList).not.toHaveClass(
            '[&>*]:pt-10',
            '[&>*:first-child]:pt-0',
        );
    });

    it('caps paragraphs and list items at a readable measure', () => {
        renderDocument();

        for (const text of [
            'What is held, and what leaves the server.',
            'Your name and your Strava athlete id.',
            'Only you see your runs.',
        ]) {
            expect(screen.getByText(text)).toHaveClass('max-w-[38rem]');
        }
        expect(
            screen.getByRole('heading', { level: 1, name: 'privacy policy' }),
        ).not.toHaveClass('max-w-[38rem]');
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
        expect(nav).toHaveTextContent('terms of use');
        expect(nav).not.toHaveTextContent('AI');
        expect(nav).toHaveTextContent('training disclaimer');
        expect(nav).not.toHaveTextContent('privacy policy');
    });

    it('drops the self-link for whichever document is showing', () => {
        renderDocument({ slug: 'terms', title: 'terms of use' });

        const nav = screen.getByRole('navigation', {
            name: 'Other documents',
        });
        expect(nav).toHaveTextContent('privacy policy');
        expect(nav).not.toHaveTextContent('terms of use');
    });
});
