import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import UserAvatarLink from './UserAvatarLink';

describe('UserAvatarLink', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('links straight to Profile', () => {
        render(<UserAvatarLink name="Ada Lovelace" avatarUrl={null} />);
        expect(
            screen.getByRole('link', { name: "Ada Lovelace's profile" }),
        ).toHaveAttribute('href', '/profile');
    });

    it('renders the initial when no avatar_url is set', () => {
        render(<UserAvatarLink name="Ada Lovelace" avatarUrl={null} />);
        expect(screen.getByText('A')).toBeInTheDocument();
    });

    it('renders the avatar image when avatar_url is provided', async () => {
        class LoadedImage {
            onload: (() => void) | null = null;
            set src(_: string) {
                queueMicrotask(() => this.onload?.());
            }
        }
        vi.stubGlobal('Image', LoadedImage);
        render(
            <UserAvatarLink
                name="Ada Lovelace"
                avatarUrl="https://example.com/a.jpg"
            />,
        );
        const link = screen.getByRole('link', {
            name: "Ada Lovelace's profile",
        });
        await waitFor(() => expect(link.querySelector('img')).not.toBeNull());
    });
});
