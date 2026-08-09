import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { formMock, setMockPage } from '@/test/setup';

import Login from './Login';

describe('Login', () => {
    it('shows the Strava CTA with the given URL', () => {
        render(<Login authStravaUrl="/auth/strava/redirect" />);
        const strava = screen.getByText(/Connect with Strava/).closest('a');
        expect(strava?.getAttribute('href')).toBe('/auth/strava/redirect');
    });

    it('appends the deep-link ?from to the Strava CTA when present', () => {
        render(
            <Login
                authStravaUrl="/auth/strava/redirect"
                from="/aktivitas/5?tab=splits"
            />,
        );
        const strava = screen.getByText(/Connect with Strava/).closest('a');
        expect(strava?.getAttribute('href')).toBe(
            '/auth/strava/redirect?from=' +
                encodeURIComponent('/aktivitas/5?tab=splits'),
        );
    });

    it('hides demo button when demoLoginEnabled is false', () => {
        render(<Login authStravaUrl="/x" />);
        expect(screen.queryByText('Try the demo')).not.toBeInTheDocument();
    });

    it('shows demo button when demoLoginEnabled is true', () => {
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        expect(screen.getByText('Try the demo')).toBeInTheDocument();
    });

    it('renders the brand hero + 3 onboarding pillars in Temari first-person voice', () => {
        render(<Login authStravaUrl="/x" />);
        expect(screen.getByText('Temari')).toBeInTheDocument();
        // Mascot intro headline includes the value-prop CTA.
        expect(screen.getByText(/Never Alone/)).toBeInTheDocument();
        expect(screen.getByText(/Hi, I'm Temari/)).toBeInTheDocument();
        [/I read/, /I record/, /I'm here for you/].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
    });

    it('renders the intro video hero with poster + play overlay', () => {
        const { container } = render(<Login authStravaUrl="/x" />);
        const video = container.querySelector('video');
        expect(video?.getAttribute('src')).toBe('/videos/intro.mp4');
        expect(video?.getAttribute('poster')).toBe('/videos/intro-poster.jpg');
        expect(screen.getByLabelText('Play intro video')).toBeInTheDocument();
    });

    it('clicking play starts the intro and hides the overlay', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        // jsdom does not implement media playback — stub play() so the handler runs.
        const playSpy = vi
            .spyOn(HTMLMediaElement.prototype, 'play')
            .mockResolvedValue();
        render(<Login authStravaUrl="/x" />);
        await userEvent
            .setup()
            .click(screen.getByLabelText('Play intro video'));
        expect(playSpy).toHaveBeenCalled();
        expect(
            screen.queryByLabelText('Play intro video'),
        ).not.toBeInTheDocument();
        playSpy.mockRestore();
    });

    it('clicking the demo button invokes the submit handler', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        setMockPage({ demoLoginEnabled: true });
        render(<Login authStravaUrl="/x" />);
        await userEvent.setup().click(screen.getByText('Try the demo'));
        expect(formMock.post).toHaveBeenCalledWith('/auth/demo');
    });

    it('shows a real sample Kartu as concrete proof of the product', () => {
        render(<Login authStravaUrl="/x" />);
        expect(
            screen.getByText('This is a real card, not a mockup'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('img', { name: '10K Sunrise' }),
        ).toBeInTheDocument();
    });

    it('keeps the play overlay visible when play() is rejected', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        const playSpy = vi
            .spyOn(HTMLMediaElement.prototype, 'play')
            .mockRejectedValue(new Error('blocked'));
        render(<Login authStravaUrl="/x" />);
        await userEvent
            .setup()
            .click(screen.getByLabelText('Play intro video'));
        expect(playSpy).toHaveBeenCalled();
        expect(screen.getByLabelText('Play intro video')).toBeInTheDocument();
        playSpy.mockRestore();
    });

    it('shows the play overlay again once the video ends', async () => {
        const userEvent = (await import('@testing-library/user-event')).default;
        const playSpy = vi
            .spyOn(HTMLMediaElement.prototype, 'play')
            .mockResolvedValue();
        const { container } = render(<Login authStravaUrl="/x" />);
        await userEvent
            .setup()
            .click(screen.getByLabelText('Play intro video'));
        expect(
            screen.queryByLabelText('Play intro video'),
        ).not.toBeInTheDocument();

        fireEvent.ended(container.querySelector('video')!);
        expect(screen.getByLabelText('Play intro video')).toBeInTheDocument();
        playSpy.mockRestore();
    });
});
