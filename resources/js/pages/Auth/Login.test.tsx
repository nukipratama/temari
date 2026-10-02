import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { formMock, setMockPage } from '@/test/setup';

import Login from './Login';

const DATA_USE = {
    headline: 'your data',
    points: ['Temari reads your Strava activities.', 'Delete it and it goes.'],
};

const DISCLAIMER = {
    headline: 'Training guidance, not medical advice',
    text: 'These numbers are training guidance, not medical advice.',
};

function disclosurePanel() {
    const trigger = screen.getByRole('button', {
        name: /how your data is used · details/,
    });
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

    it('puts the trust line under the CTA, linking to the privacy summary', () => {
        render(<Login authStravaUrl="/x" />);

        expect(
            screen.getByRole('link', {
                name: 'read-only · no ads · delete anytime',
            }),
        ).toHaveAttribute('href', '/privacy');
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
        expect(nav).not.toHaveTextContent('AI');
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
        expect(container.querySelector('svg[data-mascot]')).toBeNull();
    });

    it('clicking the demo button invokes the submit handler', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        await userEvent.setup().click(screen.getAllByText('try the demo')[0]);
        expect(formMock.post).toHaveBeenCalledWith('/auth/demo');
    });

    it('shows a real sample Card as concrete proof of the product', async () => {
        render(<Login authStravaUrl="/x" />);
        expect(
            screen.getByText(/this is a real card, not a mockup/),
        ).toBeInTheDocument();
        expect(
            await screen.findByRole('img', { name: '10K Sunrise' }),
        ).toBeInTheDocument();
    });

    it('sizes the sample card skeleton like the rem-sized tile it stands in for', async () => {
        vi.resetModules();
        const { default: FreshLogin } = await import('./Login');
        const { container } = render(<FreshLogin authStravaUrl="/x" />);
        expect(container.querySelector('.skeleton')).toHaveClass(
            'h-[5.25rem]',
            'w-[4.875rem]',
        );
        expect(
            await screen.findByRole('img', { name: '10K Sunrise' }),
        ).toHaveClass('h-[5.25rem]', 'w-[4.875rem]');
    });

    it('renders the data-use and disclaimer copy handed down by the server', () => {
        render(
            <Login
                authStravaUrl="/x"
                dataUse={DATA_USE}
                trainingDisclaimer={DISCLAIMER}
            />,
        );

        expect(disclosurePanel()).toHaveTextContent('what temari stores');
        expect(disclosurePanel()).toHaveTextContent(
            'before you take its advice',
        );
        DATA_USE.points.forEach((point) =>
            expect(screen.getByText(point)).toBeInTheDocument(),
        );
        expect(screen.getByText(DISCLAIMER.text)).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: /read the whole disclaimer/,
                hidden: true,
            }),
        ).toHaveAttribute('href', '/training-disclaimer');
    });

    it('keeps the data-use disclosure collapsed behind its one-line summary, and opens it on tap', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        render(
            <Login
                authStravaUrl="/x"
                dataUse={DATA_USE}
                trainingDisclaimer={DISCLAIMER}
            />,
        );

        const trigger = screen.getByRole('button', {
            name: /how your data is used · details/,
        });
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getByText(DATA_USE.points[0])).not.toBeVisible();

        await userEvent.setup().click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText(DATA_USE.points[0])).toBeVisible();
    });

    it('caps the hero copy to the same centred column as the sign-in lanes', () => {
        render(<Login authStravaUrl="/x" />);

        const column = screen.getByRole('heading', { level: 1 }).parentElement;
        const lane = screen
            .getByText('start with your history')
            .closest('section');

        const columnClasses = [
            'min-[900px]:mx-auto',
            'min-[900px]:max-w-column',
            'min-[1280px]:max-w-column-wide',
            'min-[900px]:px-6',
        ];
        expect(lane).toHaveClass(...columnClasses);
        expect(column).toHaveClass(...columnClasses);
        expect(column?.closest('header')).not.toHaveClass('min-[900px]:px-14');
    });

    it('omits the disclosure entirely when the server sends no copy', () => {
        render(<Login authStravaUrl="/x" />);

        expect(
            screen.queryByText(/how your data is used/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/read the whole disclaimer/),
        ).not.toBeInTheDocument();
    });
});
