import { render, screen, act } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import SettingsRow from './SettingsRow';

describe('SettingsRow', () => {
    const defaultProps = {
        icon: 'mdi:test-icon',
        label: 'Test Label',
        description: 'Test Description',
    };

    it('renders a link row with icon and description', () => {
        render(
            <SettingsRow
                {...defaultProps}
                href='/test-link'
            />
        );
        expect(screen.getByText(defaultProps.label)).toBeInTheDocument();
        expect(screen.getByText(defaultProps.description)).toBeInTheDocument();
        const link = screen.getByText(defaultProps.label).closest('a');
        expect(link).toBeInTheDocument();
        expect(link).toHaveAttribute('href', '/test-link');
    });

    it('renders an external link row', () => {
        render(
            <SettingsRow
                {...defaultProps}
                externalHref='https://example.com'
            />
        );
        expect(screen.getByText(defaultProps.label)).toBeInTheDocument();
        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', 'https://example.com');
    });

    it('renders a button row with onClick handler and children', () => {
        const onClick = vi.fn();
        render(
            <SettingsRow
                {...defaultProps}
                onClick={onClick}
            >
                <span>Child Element</span>
            </SettingsRow>
        );
        expect(screen.getByText(defaultProps.label)).toBeInTheDocument();
        const button = screen.getByText(defaultProps.label).closest('button')!;
        expect(button).toBeInTheDocument();
        act(() => {
            button.click();
        });
        expect(onClick).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Child Element')).toBeInTheDocument();
    });

    it('renders a plain div row when no trigger is provided', () => {
        render(<SettingsRow {...defaultProps} />);
        expect(screen.getByText(defaultProps.label)).toBeInTheDocument();
        const div = screen.getByText(defaultProps.label).closest('div');
        expect(div).toBeInTheDocument();
        expect(div).toHaveClass('focus-ring', 'hover:bg-cream-deep/40');
    });
});
