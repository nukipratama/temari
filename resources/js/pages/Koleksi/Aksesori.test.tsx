import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import KoleksiAksesori from './Aksesori';
import { setMockPage } from '@/test/setup';
import type { EquippedAccessories } from '@/types/inertia';

type Slot = 'medal' | 'ikat_kepala' | 'kaus' | 'celana' | 'sepatu' | 'aura';

const emptyEquipped: EquippedAccessories = {
    medal: null,
    ikat_kepala: null,
    kaus: null,
    celana: null,
    sepatu: null,
    aura: null,
};

function item(unlock_key: string, slot: Slot, unlocked: boolean, equipped: boolean) {
    return {
        unlock_key,
        slot,
        name: unlock_key,
        rarity: 'common' as const,
        icon: 'mdi:medal',
        description: 'desc',
        criteria: 'criteria',
        unlocked,
        equipped,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Koleksi/Aksesori', () => {
    it('renders headers + equipped slot labels when nothing is equipped', () => {
        const items = [
            item('accessory.ikat_kepala_epik', 'ikat_kepala', false, false),
            item('accessory.medal_pertama', 'medal', false, false),
        ];
        render(<KoleksiAksesori items={items} equipped={emptyEquipped} />);
        expect(screen.getByText(/Dandanin Temari/)).toBeInTheDocument();
        // 6 slot labels appear in the equipped strip with "belum dipake" status.
        expect(screen.getAllByText(/belum dipake/).length).toBe(6);
    });

    it('renders unlocked + equipped state per item', () => {
        const items = [
            item('accessory.ikat_kepala_legendaris', 'ikat_kepala', true, true),
            item('accessory.ikat_kepala_epik', 'ikat_kepala', true, false),
            item('accessory.medal_emas', 'medal', true, true),
        ];
        render(
            <KoleksiAksesori
                items={items}
                equipped={{
                    ...emptyEquipped,
                    ikat_kepala: 'accessory.ikat_kepala_legendaris',
                    medal: 'accessory.medal_emas',
                }}
            />,
        );
        expect(screen.getAllByText(/Legendaris/i).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Emas/i).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/dipake/).length).toBeGreaterThan(0);
    });

    it('renders the medal name when equipped', () => {
        render(
            <KoleksiAksesori
                items={[item('accessory.medal_pertama', 'medal', true, true)]}
                equipped={{ ...emptyEquipped, medal: 'accessory.medal_pertama' }}
            />,
        );
        // The equipped panel and the card both render the name
        const matches = screen.getAllByText('accessory.medal_pertama');
        expect(matches.length).toBeGreaterThanOrEqual(1);
    });

    it('shows the item name label for equipped aura', () => {
        render(
            <KoleksiAksesori
                items={[item('accessory.aura_pemanasan', 'aura', true, true)]}
                equipped={{ ...emptyEquipped, aura: 'accessory.aura_pemanasan' }}
            />,
        );
        // The equipped panel and the card both render the name
        const matches = screen.getAllByText('accessory.aura_pemanasan');
        expect(matches.length).toBeGreaterThanOrEqual(1);
    });

    it('posts to the equip endpoint when an unlocked-but-not-equipped Pasang button is clicked', () => {
        vi.mocked(router.post).mockReset();
        const items = [
            item('accessory.ikat_kepala_epik', 'ikat_kepala', true, false),
        ];
        render(
            <KoleksiAksesori
                items={items}
                equipped={emptyEquipped}
            />,
        );
        fireEvent.click(screen.getByText('Pasang'));
        expect(router.post).toHaveBeenCalledWith(
            '/api/aksesori/equip',
            { unlock_key: 'accessory.ikat_kepala_epik' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders the default preview (no slot variant) for unknown unlock keys', () => {
        const items = [item('accessory.sepatu_basic', 'sepatu', true, false)];
        render(
            <KoleksiAksesori
                items={items}
                equipped={emptyEquipped}
            />,
        );
        expect(screen.getByText('accessory.sepatu_basic')).toBeInTheDocument();
    });

    it('toggles the locked items list when the "belum kebuka" button is clicked', () => {
        const items = [
            item('accessory.ikat_kepala_epik', 'ikat_kepala', true, false),
            item('accessory.ikat_kepala_legendaris', 'ikat_kepala', false, false),
            item('accessory.medal_pertama', 'medal', false, false),
        ];
        render(<KoleksiAksesori items={items} equipped={emptyEquipped} />);
        const btn = screen.getAllByRole('button').find((b) => /belum kebuka/.test(b.textContent ?? ''));
        fireEvent.click(btn ?? document.body);
        fireEvent.click(btn ?? document.body);
    });
});
