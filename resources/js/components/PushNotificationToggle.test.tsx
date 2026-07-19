import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import PushNotificationToggle from './PushNotificationToggle';
import { setMockPage } from '@/test/setup';
import * as webPush from '@/lib/webPush';

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

    fireEvent.click(await screen.findByRole('button', { name: /Nyalakan notifikasi/ }));

    await waitFor(() => expect(webPush.subscribe).toHaveBeenCalledWith('test-key'));
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
    expect(await screen.findByText(/Buka Temari di Safari/)).toBeInTheDocument();
});

it('shows the unsupported hint', async () => {
    vi.mocked(webPush.isPushSupported).mockReturnValue(false);
    render(<PushNotificationToggle />);
    expect(await screen.findByText(/belum bisa nerima notifikasi/)).toBeInTheDocument();
});

it('shows the OS-settings hint when permission is denied', async () => {
    vi.stubGlobal('Notification', { permission: 'denied' });
    render(<PushNotificationToggle />);
    expect(await screen.findByText(/diblokir/i)).toBeInTheDocument();
});

it('offers only the off switch when already subscribed', async () => {
    vi.mocked(webPush.currentSubscription).mockResolvedValue({} as PushSubscription);
    render(<PushNotificationToggle />);
    expect(await screen.findByRole('button', { name: /Matikan/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Kirim tes/ })).not.toBeInTheDocument();
});
