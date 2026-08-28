import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import * as webPush from '@/lib/webPush';
import { setMockPage } from '@/test/setup';

import PushNotificationToggle from './PushNotificationToggle';

vi.mock('@/lib/webPush');

function mockPage(webPushPublicKey: string = 'test-key') {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
        webPushPublicKey,
    });
}

beforeEach(() => {
    vi.mocked(webPush.isPushSupported).mockReturnValue(true);
    vi.mocked(webPush.isStandalone).mockReturnValue(true);
    vi.mocked(webPush.isIosNonSafari).mockReturnValue(false);
    vi.mocked(webPush.currentSubscription).mockResolvedValue(null);
    vi.mocked(webPush.subscribe).mockResolvedValue(undefined);
    vi.mocked(webPush.unsubscribe).mockResolvedValue(undefined);
    vi.stubGlobal('Notification', { permission: 'default' });
    mockPage();
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

it('renders nothing when no VAPID public key is configured', () => {
    mockPage('');
    const { container } = render(<PushNotificationToggle />);
    expect(container).toBeEmptyDOMElement();
});

it('shows the enable button when ready and subscribes on click', async () => {
    render(<PushNotificationToggle />);

    fireEvent.click(await screen.findByRole('button', { name: /Turn on/ }));

    await waitFor(() =>
        expect(webPush.subscribe).toHaveBeenCalledWith('test-key'),
    );
});

it('shows the Home-Screen install hint on Safari when not standalone', async () => {
    vi.mocked(webPush.isStandalone).mockReturnValue(false);
    render(<PushNotificationToggle />);
    expect(await screen.findByText(/Add to Home Screen/)).toBeInTheDocument();
});

it('tells a non-Safari iOS browser to open in Safari', async () => {
    vi.mocked(webPush.isStandalone).mockReturnValue(false);
    vi.mocked(webPush.isIosNonSafari).mockReturnValue(true);
    render(<PushNotificationToggle />);
    expect(
        await screen.findByText(/Open Temari in Safari/),
    ).toBeInTheDocument();
});

it('shows the unsupported hint', async () => {
    vi.mocked(webPush.isPushSupported).mockReturnValue(false);
    render(<PushNotificationToggle />);
    expect(
        await screen.findByText(/can't receive notifications/),
    ).toBeInTheDocument();
});

it('shows the OS-settings hint when permission is denied', async () => {
    vi.stubGlobal('Notification', { permission: 'denied' });
    render(<PushNotificationToggle />);
    expect(await screen.findByText(/blocked/i)).toBeInTheDocument();
});

it('offers only the off switch when already subscribed', async () => {
    vi.mocked(webPush.currentSubscription).mockResolvedValue(
        {} as PushSubscription,
    );
    render(<PushNotificationToggle />);
    expect(
        await screen.findByRole('button', { name: /Turn off/ }),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /Send test notification/ }),
    ).not.toBeInTheDocument();
});

it('unsubscribes and returns to the ready state when Turn off is clicked', async () => {
    vi.mocked(webPush.currentSubscription).mockResolvedValue(
        {} as PushSubscription,
    );
    render(<PushNotificationToggle />);

    fireEvent.click(await screen.findByRole('button', { name: /Turn off/ }));

    await waitFor(() => expect(webPush.unsubscribe).toHaveBeenCalled());
    expect(
        await screen.findByRole('button', { name: /Turn on/ }),
    ).toBeInTheDocument();
});

it('renders a mute toggle instead of the action once subscribed, when onMuteChange is passed', async () => {
    vi.mocked(webPush.currentSubscription).mockResolvedValue(
        {} as PushSubscription,
    );
    const onMuteChange = vi.fn();
    render(
        <PushNotificationToggle muted={false} onMuteChange={onMuteChange} />,
    );

    const toggle = await screen.findByRole('switch', {
        name: 'Send run notifications to this device',
    });
    fireEvent.click(toggle);

    expect(onMuteChange).toHaveBeenCalledWith(true);
});

it('opens and closes the demo-blocked modal for a demo user', async () => {
    mockPage();
    setMockPage({
        auth: {
            user: {
                id: 1,
                name: 'A',
                first_name: 'A',
                avatar_url: null,
                is_demo: true,
            },
        },
        flash: {},
        demoLoginEnabled: false,
        webPushPublicKey: 'test-key',
    });
    render(<PushNotificationToggle />);

    fireEvent.click(await screen.findByRole('button', { name: /Turn on/ }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /close/i }));
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
});
