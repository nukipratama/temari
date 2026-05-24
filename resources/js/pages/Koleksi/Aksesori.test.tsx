import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import KoleksiAksesori from './Aksesori';
import { setMockPage } from '@/test/setup';

type Slot = 'headband' | 'medal' | 'pita' | 'aura';

function item(unlock_key: string, slot: Slot, unlocked: boolean, equipped: boolean) {
    return {
        unlock_key,
        slot,
        name: unlock_key,
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
            item('accessory.headband_epik', 'headband', false, false),
            item('accessory.medal_first_pr', 'medal', false, false),
        ];
        render(<KoleksiAksesori items={items} equipped={{ headband: null, medal: null, pita: false, aura: false }} />);
        expect(screen.getByText(/Dandanin Temari/)).toBeInTheDocument();
        // 4 slot labels appear in the equipped strip with "belum dipake" status.
        expect(screen.getAllByText(/belum dipake/).length).toBeGreaterThan(0);
    });

    it('renders unlocked + equipped state per item', () => {
        const items = [
            item('accessory.headband_legendaris', 'headband', true, true),
            item('accessory.headband_epik', 'headband', true, false),
            item('accessory.medal_gold', 'medal', true, true),
            item('accessory.weekly_streak_4', 'pita', true, true),
        ];
        render(
            <KoleksiAksesori
                items={items}
                equipped={{ headband: 'legendaris', medal: 'emas', pita: true, aura: false }}
            />,
        );
        expect(screen.getAllByText(/Legendaris/i).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Emas/i).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/dipake/).length).toBeGreaterThan(0);
    });

    it('renders the medal=pertama label when first PR medal is equipped', () => {
        render(
            <KoleksiAksesori
                items={[]}
                equipped={{ headband: null, medal: 'pertama', pita: false, aura: false }}
            />,
        );
        expect(screen.getByText(/Pertama/)).toBeInTheDocument();
    });

    it('renders ember headband variant when only the base headband is equipped', () => {
        render(
            <KoleksiAksesori
                items={[]}
                equipped={{ headband: 'ember', medal: null, pita: false, aura: true }}
            />,
        );
        expect(screen.getByText(/Ember/)).toBeInTheDocument();
        expect(screen.getByText(/aktif/)).toBeInTheDocument();
    });

    it('posts to the equip endpoint when an unlocked-but-not-equipped Pasang button is clicked', () => {
        vi.mocked(router.post).mockReset();
        const items = [
            item('accessory.headband_epik', 'headband', true, false),
        ];
        render(
            <KoleksiAksesori
                items={items}
                equipped={{ headband: null, medal: null, pita: false, aura: false }}
            />,
        );
        fireEvent.click(screen.getByText('Pasang'));
        expect(router.post).toHaveBeenCalledWith(
            '/api/aksesori/equip',
            { unlock_key: 'accessory.headband_epik' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders the default preview (no slot variant) for unknown unlock keys', () => {
        const items = [item('accessory.aura_legendaris', 'aura', true, false)];
        render(
            <KoleksiAksesori
                items={items}
                equipped={{ headband: null, medal: null, pita: false, aura: false }}
            />,
        );
        expect(screen.getByText('accessory.aura_legendaris')).toBeInTheDocument();
    });
});
