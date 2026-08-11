import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { EquippedAccessories } from '@/types/inertia';

import { setMockPage, stubSyncAnimationFrame } from '@/test/setup';

import Accessories from './Accessories';

type Slot = 'medal' | 'headband' | 'shirt' | 'shorts' | 'shoes' | 'aura';

const emptyEquipped: EquippedAccessories = {
    medal: null,
    headband: null,
    shirt: null,
    shorts: null,
    shoes: null,
    aura: null,
};

function item(
    unlock_key: string,
    slot: Slot,
    unlocked: boolean,
    equipped: boolean,
) {
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
        current: 1,
        target: 5,
        unit: 'runs',
    };
}

beforeEach(() => {
    setMockPage({
        auth: {
            user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null },
        },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Collection/Accessories', () => {
    it('coach-marks the dress-up preview on a first visit', () => {
        window.localStorage.clear();
        stubSyncAnimationFrame();
        render(
            <Accessories
                items={[item('accessory.medal_gold', 'medal', true, false)]}
                equipped={emptyEquipped}
            />,
        );
        expect(
            screen.getByRole('dialog', { name: 'Try things on' }),
        ).toBeInTheDocument();
    });

    it('renders headers + equipped slot labels when nothing is equipped', () => {
        const items = [
            item('accessory.headband_epic', 'headband', false, false),
            item('accessory.medal_first', 'medal', false, false),
        ];
        render(<Accessories items={items} equipped={emptyEquipped} />);
        expect(screen.getByText(/Dress up Temari/)).toBeInTheDocument();
        // 6 slot labels appear in the equipped strip with "not equipped" status.
        expect(screen.getAllByText(/not equipped/).length).toBe(6);
    });

    it('renders unlocked + equipped state per item', () => {
        const items = [
            item('accessory.headband_legendary', 'headband', true, true),
            item('accessory.headband_epic', 'headband', true, false),
            item('accessory.medal_gold', 'medal', true, true),
        ];
        render(
            <Accessories
                items={items}
                equipped={{
                    ...emptyEquipped,
                    headband: 'accessory.headband_legendary',
                    medal: 'accessory.medal_gold',
                }}
            />,
        );
        expect(screen.getAllByText(/Legendary/i).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Gold/i).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/equipped/i).length).toBeGreaterThan(0);
    });

    it('renders the medal name when equipped', () => {
        render(
            <Accessories
                items={[item('accessory.medal_first', 'medal', true, true)]}
                equipped={{
                    ...emptyEquipped,
                    medal: 'accessory.medal_first',
                }}
            />,
        );
        // The equipped panel and the card both render the name
        const matches = screen.getAllByText('accessory.medal_first');
        expect(matches.length).toBeGreaterThanOrEqual(1);
    });

    it('shows the item name label for equipped aura', () => {
        render(
            <Accessories
                items={[item('accessory.aura_warmup', 'aura', true, true)]}
                equipped={{
                    ...emptyEquipped,
                    aura: 'accessory.aura_warmup',
                }}
            />,
        );
        // The equipped panel and the card both render the name
        const matches = screen.getAllByText('accessory.aura_warmup');
        expect(matches.length).toBeGreaterThanOrEqual(1);
    });

    it('posts to the equip endpoint when an unlocked-but-not-equipped Equip button is clicked', () => {
        vi.mocked(router.post).mockReset();
        const items = [
            item('accessory.headband_epic', 'headband', true, false),
        ];
        render(<Accessories items={items} equipped={emptyEquipped} />);
        fireEvent.click(screen.getByText('Equip'));
        expect(router.post).toHaveBeenCalledWith(
            '/api/accessories/equip',
            { unlock_key: 'accessory.headband_epic' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders the default preview (no slot variant) for unknown unlock keys', () => {
        const items = [item('accessory.shoes_basic', 'shoes', true, false)];
        render(<Accessories items={items} equipped={emptyEquipped} />);
        expect(screen.getByText('accessory.shoes_basic')).toBeInTheDocument();
    });

    it('shows live progress numbers on a locked item', async () => {
        const items = [
            {
                ...item('accessory.medal_gold', 'medal', false, false),
                current: 2,
                target: 5,
                unit: 'PR',
            },
        ];
        render(<Accessories items={items} equipped={emptyEquipped} />);
        // The current-value count-up tweens from 0 on mount.
        await waitFor(() =>
            expect(
                screen.getByText((_, el) => el?.textContent === '2/5'),
            ).toBeInTheDocument(),
        );
        expect(screen.getByText('PR')).toBeInTheDocument();
    });

    it('toggles the locked items list when the "locked" button is clicked', () => {
        const items = [
            item('accessory.headband_epic', 'headband', true, false),
            item('accessory.headband_legendary', 'headband', false, false),
            item('accessory.medal_first', 'medal', false, false),
        ];
        render(<Accessories items={items} equipped={emptyEquipped} />);
        // Each slot section has its own toggle + locked-item wrapper, so scope
        // both to the headband section rather than the first "locked"
        // button in the document (medal's section also has one).
        const section = screen
            .getByText('accessory.headband_legendary')
            .closest('section');
        const btn =
            section &&
            Array.from(section.querySelectorAll('button')).find((b) =>
                /locked/.test(b.textContent ?? ''),
            );
        // The locked-item wrapper is hidden on mobile until toggled ("hidden sm:contents").
        const lockedWrapper = screen
            .getByText('accessory.headband_legendary')
            .closest('article')?.parentElement;
        expect(lockedWrapper?.className).toBe('hidden sm:contents');

        fireEvent.click(btn ?? document.body);
        expect(lockedWrapper?.className).toBe('contents');

        fireEvent.click(btn ?? document.body);
        expect(lockedWrapper?.className).toBe('hidden sm:contents');
    });
});
