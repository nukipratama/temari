import { usePage } from '@inertiajs/react';
import TemariProto, { type TemariEquipped, type TemariProtoProps } from './TemariProto';
import type { SharedProps } from '@/types/inertia';

/**
 * The mascot as the signed-in user has dressed it — reads the globally-shared
 * `equippedAccessories` and renders TemariProto with them, so a hard-earned
 * headband/medal shows up everywhere Temari appears, not just on the Aksesori
 * page. Use this for any ambient mascot. Sites that must show a *specific*
 * accessory (the equip-picker preview, the just-unlocked celebration) render
 * TemariProto directly with an explicit `equipped`.
 */
export default function Temari(props: Readonly<Omit<TemariProtoProps, 'equipped'>>) {
    const equippedAccessories = usePage<SharedProps>().props.equippedAccessories ?? null;

    const equipped: TemariEquipped | null = equippedAccessories
        ? {
              headband: equippedAccessories.headband,
              medal: equippedAccessories.medal ?? 'none',
              pita: equippedAccessories.pita,
              aura: equippedAccessories.aura,
          }
        : null;

    return <TemariProto {...props} equipped={equipped} />;
}
