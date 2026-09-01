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

function disclosurePanel() {
    const trigger = screen.getByRole('button', { name: /data & AI use/ });
    return document.getElementById(trigger.getAttribute('aria-controls') ?? '');
}

function stravaLinks() {
    return screen
        .getAllByText('connect with Strava')
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
        expect(screen.queryByText('try the demo')).not.toBeInTheDocument();
    });

    it('shows demo button when demoLoginEnabled is true', () => {
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        expect(screen.getAllByText('try the demo').length).toBeGreaterThan(0);
    });

    it('lets a stranger read the legal pages before connecting anything', () => {
        render(<Login authStravaUrl="/x" />);

        const nav = screen.getByRole('navigation', { name: 'Legal' });
        expect(nav).toHaveTextContent('terms');
        expect(nav).toHaveTextContent('privacy');
        expect(nav).toHaveTextContent('how temari uses AI');
        expect(nav).toHaveTextContent('training disclaimer');
        expect(screen.getByRole('link', { name: 'privacy' })).toHaveAttribute(
            'href',
            '/privacy',
        );
    });

    it('leads with the you-vs-past-you promise, not with the Strava ask', () => {
        render(<Login authStravaUrl="/x" />);

        expect(screen.getByText(/past you/i)).toBeInTheDocument();
        expect(
            screen.getByText(/matched against one you have already done/i),
        ).toBeInTheDocument();
        expect(screen.getByText('running companion')).toBeInTheDocument();
        expect(
            screen.getByText('temari · your running companion, every step'),
        ).toBeInTheDocument();
    });

    it('explains why the comparison is fair before asking for access', () => {
        render(<Login authStravaUrl="/x" />);

        expect(screen.getByText('fair matches only')).toBeInTheDocument();
        expect(
            screen.getByText('reads the gap, not the vibe'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('says when it cannot tell'),
        ).toBeInTheDocument();
    });

    it('lists what a connected account gets', () => {
        render(<Login authStravaUrl="/x" />);

        expect(
            screen.getByText('a plan that answers to your week'),
        ).toBeInTheDocument();
        expect(screen.getByText('records and recaps')).toBeInTheDocument();
    });

    it("draws no face in the hero panel — the prototype's login has none", () => {
        const { container } = render(<Login authStravaUrl="/x" />);
        expect(container.querySelector('[data-face-icon]')).toBeNull();
    });

    it('clicking the demo button invokes the submit handler', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        await userEvent.setup().click(screen.getAllByText('try the demo')[0]);
        expect(formMock.post).toHaveBeenCalledWith('/auth/demo');
    });

    it('shows a real sample Kartu as concrete proof of the product', async () => {
        render(<Login authStravaUrl="/x" />);
        expect(
            screen.getByText(/this is a real card, not a mockup/),
        ).toBeInTheDocument();
        expect(
            await screen.findByRole('img', { name: '10K Sunrise' }),
        ).toBeInTheDocument();
    });

    it('renders the data-use and disclaimer copy handed down by the server', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        render(
            <Login
                authStravaUrl="/x"
                dataUse={DATA_USE}
                trainingDisclaimer={DISCLAIMER}
            />,
        );

        await userEvent
            .setup()
            .click(screen.getByRole('button', { name: /data & AI use/ }));

        // "what temari stores" is also the auth card's footnote link, so the
        // headings are asserted against the disclosure panel.
        expect(disclosurePanel()).toHaveTextContent('what temari stores');
        expect(disclosurePanel()).toHaveTextContent(
            'before you take its advice',
        );
        DATA_USE.points.forEach((point) =>
            expect(screen.getByText(point)).toBeInTheDocument(),
        );
        expect(screen.getByText(DISCLAIMER.text)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /read the whole disclaimer/ }),
        ).toHaveAttribute('href', '/training-disclaimer');
    });

    it('keeps the data & AI use disclosure closed until it is asked for', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        render(
            <Login
                authStravaUrl="/x"
                dataUse={DATA_USE}
                trainingDisclaimer={DISCLAIMER}
            />,
        );

        const trigger = screen.getByRole('button', { name: /data & AI use/ });
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getByText(DATA_USE.points[0])).not.toBeVisible();

        await userEvent.setup().click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText(DATA_USE.points[0])).toBeVisible();
    });

    it('omits the disclosure entirely when the server sends no copy', () => {
        render(<Login authStravaUrl="/x" />);

        expect(screen.queryByText(/data & AI use/)).not.toBeInTheDocument();
        expect(
            screen.queryByText(/read the whole disclaimer/),
        ).not.toBeInTheDocument();
    });
});
