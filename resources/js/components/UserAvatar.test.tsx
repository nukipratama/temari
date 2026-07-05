import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import UserAvatar from './UserAvatar';

describe('UserAvatar', () => {
    it('renders an image when an avatarUrl is given', () => {
        const { container } = render(<UserAvatar name="Ada Lovelace" avatarUrl="https://example.test/a.png" />);
        const img = container.querySelector('img')!;
        expect(img).toHaveAttribute('src', 'https://example.test/a.png');
    });

    it('falls back to the first letter of the name when there is no avatarUrl', () => {
        render(<UserAvatar name="Bianca" avatarUrl={null} />);
        expect(screen.getByText('B')).toBeInTheDocument();
    });

    it('uses the larger size classes by default and smaller ones for size="sm"', () => {
        const { container: md } = render(<UserAvatar name="Bianca" avatarUrl={null} />);
        expect(md.querySelector('span')!.className).toContain('h-9');

        const { container: sm } = render(<UserAvatar name="Bianca" avatarUrl={null} size="sm" />);
        expect(sm.querySelector('span')!.className).toContain('h-8');
    });
});
