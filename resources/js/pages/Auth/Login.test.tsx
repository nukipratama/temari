import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { formMock, setMockPage } from '@/test/setup';

import Login from './Login';

const DATA_USE = {
    headline: 'Your data',
    points: ['Temari reads your Strava activities.', 'Delete it and it goes.'],
};

const DISCLAIMER = {
    headline: 'Training guidance, not medical advice',
    text: 'These numbers are training guidance, not medical advice.',
};

function stravaLinks() {
    return screen
        .getAllByText('Connect with Strava')
        .map((node) => node.closest('a'));
}

describe('Login', () => {
    it('shows the Strava CTA with the given URL', () => {
        render(<Login authStravaUrl="/auth/strava/redirect" />);

        const links = stravaLinks();
        expect(links.length).toBeGreaterThan(0);
        links.forEach((link) =>
            expect(link?.getAttribute('href')).toBe('/auth/strava/redirect'),
        );
    });

    it('appends the deep-link ?from to every Strava CTA when present', () => {
        render(
            <Login
                authStravaUrl="/auth/strava/redirect"
                from="/activities/5?tab=splits"
            />,
        );

        stravaLinks().forEach((link) =>
            expect(link?.getAttribute('href')).toBe(
                '/auth/strava/redirect?from=' +
                    encodeURIComponent('/activities/5?tab=splits'),
            ),
        );
    });

    it('hides demo button when demoLoginEnabled is false', () => {
        render(<Login authStravaUrl="/x" />);
        expect(screen.queryByText('Try the demo')).not.toBeInTheDocument();
    });

    it('shows demo button when demoLoginEnabled is true', () => {
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        expect(screen.getAllByText('Try the demo').length).toBeGreaterThan(0);
    });

    it('lets a stranger read the legal pages before connecting anything', () => {
        render(<Login authStravaUrl="/x" />);

        const nav = screen.getByRole('navigation', { name: 'Legal' });
        expect(nav).toHaveTextContent('Terms');
        expect(nav).toHaveTextContent('Privacy');
        expect(nav).toHaveTextContent('How Temari uses AI');
        expect(nav).toHaveTextContent('Training disclaimer');
        expect(screen.getByRole('link', { name: 'Privacy' })).toHaveAttribute(
            'href',
            '/privacy',
        );
    });

    it('leads with the you-vs-past-you promise, not with the Strava ask', () => {
        render(<Login authStravaUrl="/x" />);

        expect(screen.getByText(/past you/i)).toBeInTheDocument();
        expect(
            screen.getByText(/matched against a run you have already done/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/no leaderboards/i)).toBeInTheDocument();
    });

    it('explains how the comparison is made before asking for access', () => {
        render(<Login authStravaUrl="/x" />);

        expect(screen.getByText('It finds a fair match')).toBeInTheDocument();
        expect(
            screen.getByText('It reads the gap, not the vibe'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('It says when it cannot tell'),
        ).toBeInTheDocument();
    });

    it('renders the Temari mascot in the hero panel', () => {
        const { container } = render(<Login authStravaUrl="/x" />);
        expect(
            container.querySelector('[data-pose="glow"]'),
        ).toBeInTheDocument();
    });

    it('clicking the demo button invokes the submit handler', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        await userEvent.setup().click(screen.getAllByText('Try the demo')[0]);
        expect(formMock.post).toHaveBeenCalledWith('/auth/demo');
    });

    it('shows a real sample Kartu as concrete proof of the product', async () => {
        render(<Login authStravaUrl="/x" />);
        expect(
            screen.getByText('This is a real card, not a mockup'),
        ).toBeInTheDocument();
        expect(
            await screen.findByRole('img', { name: '10K Sunrise' }),
        ).toBeInTheDocument();
    });

    it('renders the data-use and disclaimer copy handed down by the server', () => {
        render(
            <Login
                authStravaUrl="/x"
                dataUse={DATA_USE}
                trainingDisclaimer={DISCLAIMER}
            />,
        );

        expect(screen.getByText(DATA_USE.headline)).toBeInTheDocument();
        DATA_USE.points.forEach((point) =>
            expect(screen.getByText(point)).toBeInTheDocument(),
        );
        expect(screen.getByText(DISCLAIMER.headline)).toBeInTheDocument();
        expect(screen.getByText(DISCLAIMER.text)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Read the whole disclaimer/ }),
        ).toHaveAttribute('href', '/training-disclaimer');
    });

    it('omits the sourced-copy sections entirely when the server sends none', () => {
        render(<Login authStravaUrl="/x" />);

        expect(screen.queryByText('Your data')).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Read the whole disclaimer/),
        ).not.toBeInTheDocument();
    });
});
