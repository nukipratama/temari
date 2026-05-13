import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import AppShell from './AppShell';
import { setMockPage } from '@/test/setup';

describe('AppShell', () => {
    it('renders header + children by default', () => {
        setMockPage({
            auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
            flash: {},
            demoLoginEnabled: false,
        });
        render(
            <AppShell>
                <p>child content</p>
            </AppShell>,
        );
        expect(screen.getByText('child content')).toBeInTheDocument();
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
    });

    it('omits header when showHeader is false', () => {
        setMockPage({ auth: { user: null }, flash: {}, demoLoginEnabled: false });
        render(
            <AppShell showHeader={false}>
                <p>only child</p>
            </AppShell>,
        );
        expect(screen.queryByText('TemanLari')).not.toBeInTheDocument();
        expect(screen.getByText('only child')).toBeInTheDocument();
    });
});
