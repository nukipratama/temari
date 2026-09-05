import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import UserAvatarLink from './UserAvatarLink';

describe('UserAvatarLink', () => {
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

    it('renders the avatar image when avatar_url is provided', () => {
        render(
            <UserAvatarLink
                name="Ada Lovelace"
                avatarUrl="https://example.com/a.jpg"
            />,
        );
        const link = screen.getByRole('link', {
            name: "Ada Lovelace's profile",
        });
        expect(link.querySelector('img')).not.toBeNull();
    });
});
