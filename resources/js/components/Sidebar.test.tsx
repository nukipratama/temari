import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Sidebar from './Sidebar';
import { SidebarProvider } from '@/contexts/SidebarContext';
import { setMockPage } from '@/test/setup';

const baseUser = { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null };

function setupPage(url = '/dashboard', user: typeof baseUser | null = baseUser) {
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
    it('renders all 4 main nav links', () => {
        setupPage();
        renderSidebar();
        // Each link appears twice (persistent + dialog drawer). Use
        // getAllByText to assert at least one of each exists.
        ['Beranda', 'Aktivitas', 'Kartu', 'Catatan'].forEach((label) => {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        });
    });

    it('marks the active link based on current url', () => {
        setupPage('/runs');
        const { container } = renderSidebar();
        const activeLink = container.querySelector('a.border-brand-500');
        expect(activeLink).not.toBeNull();
        expect(activeLink?.textContent).toContain('Aktivitas');
    });

    it('treats nested url as still active for parent link (/runs/123)', () => {
        setupPage('/runs/123');
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
        setupPage('/dashboard', null);
        renderSidebar();
        expect(screen.queryByText('Tap untuk menu')).not.toBeInTheDocument();
    });

    it('clicking the user chip opens the menu with Profil / Pengaturan / Keluar', () => {
        setupPage();
        renderSidebar();
        const chips = screen.getAllByRole('button', { name: /Ada/ });
        fireEvent.click(chips[0]);
        // Menu items appear once per opened menu.
        expect(screen.getAllByRole('menuitem', { name: /Profil/ }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('menuitem', { name: /Pengaturan/ }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('menuitem', { name: /Keluar/ }).length).toBeGreaterThan(0);
    });

    it('Escape closes the open menu', () => {
        setupPage();
        renderSidebar();
        const chip = screen.getAllByRole('button', { name: /Ada/ })[0];
        fireEvent.click(chip);
        expect(screen.getAllByRole('menuitem', { name: /Profil/ }).length).toBeGreaterThan(0);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('menuitem', { name: /Profil/ })).not.toBeInTheDocument();
    });

    it('click-outside closes the menu', () => {
        setupPage();
        const { container } = renderSidebar();
        fireEvent.click(screen.getAllByRole('button', { name: /Ada/ })[0]);
        expect(screen.getAllByRole('menuitem', { name: /Profil/ }).length).toBeGreaterThan(0);
        fireEvent.mouseDown(container);
        expect(screen.queryByRole('menuitem', { name: /Profil/ })).not.toBeInTheDocument();
    });

    it('logout calls router.post', async () => {
        const inertia = await import('@inertiajs/react');
        setupPage();
        renderSidebar();
        fireEvent.click(screen.getAllByRole('button', { name: /Ada/ })[0]);
        const logoutBtn = screen.getAllByRole('menuitem', { name: /Keluar/ })[0];
        fireEvent.click(logoutBtn);
        expect(vi.mocked(inertia.router.post)).toHaveBeenCalledWith('/logout');
    });

    it('renders the avatar image when avatar_url is set', () => {
        setupPage('/dashboard', { ...baseUser, avatar_url: 'https://example.com/a.jpg' });
        renderSidebar();
        const imgs = screen.getAllByAltText('Ada') as HTMLImageElement[];
        expect(imgs[0].src).toBe('https://example.com/a.jpg');
    });
});
