import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Sidebar from './Sidebar';
import { SidebarProvider } from '@/contexts/SidebarContext';
import { setMockPage } from '@/test/setup';

const baseUser = { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null as string | null };

function setupPage(url = '/', user: typeof baseUser | null = baseUser) {
    setMockPage(
        {
            auth: { user },
            flash: { success: null, error: null, info: null },
            demoLoginEnabled: false,
            onboarding: { forceShow: false },
        },
        url,
    );
}

function renderSidebar() {
    return render(
        <SidebarProvider>
            <Sidebar />
        </SidebarProvider>,
    );
}

describe('Sidebar', () => {
    it('renders all main nav links', () => {
        setupPage();
        renderSidebar();
        // Each link appears twice (persistent + dialog drawer). Use
        // getAllByText to assert at least one of each exists.
        ['Beranda', 'Aktivitas', 'Kartu', 'Catatan', 'Rekor'].forEach((label) => {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        });
    });

    it('marks the active link based on current url', () => {
        setupPage('/aktivitas');
        const { container } = renderSidebar();
        const activeLink = container.querySelector('a.border-brand-500');
        expect(activeLink).not.toBeNull();
        expect(activeLink?.textContent).toContain('Aktivitas');
    });

    it('treats nested url as still active for parent link (/aktivitas/123)', () => {
        setupPage('/aktivitas/123');
        const { container } = renderSidebar();
        const activeLink = container.querySelector('a.border-brand-500');
        expect(activeLink?.textContent).toContain('Aktivitas');
    });

    it('renders user chip with name when authed', () => {
        setupPage();
        renderSidebar();
        // 2 instances — persistent sidebar + dialog drawer.
        expect(screen.getAllByText('Ada').length).toBeGreaterThan(0);
    });

    it('hides user chip when user is null', () => {
        setupPage('/', null);
        renderSidebar();
        expect(screen.queryByText('Ada')).not.toBeInTheDocument();
    });

    it('renders Profil and Pengaturan as nav links', () => {
        setupPage();
        renderSidebar();
        expect(screen.getAllByRole('link', { name: /Profil/ }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: /Pengaturan/ }).length).toBeGreaterThan(0);
    });

    it('marks Pengaturan as active when on /pengaturan', () => {
        setupPage('/pengaturan');
        const { container } = renderSidebar();
        const activeLink = container.querySelector('a.border-brand-500');
        expect(activeLink?.textContent).toContain('Pengaturan');
    });

    it('renders the avatar image when avatar_url is set', () => {
        setupPage('/', { ...baseUser, avatar_url: 'https://example.com/a.jpg' });
        renderSidebar();
        const imgs = screen.getAllByAltText('Ada') as HTMLImageElement[];
        expect(imgs[0].src).toBe('https://example.com/a.jpg');
    });
});
