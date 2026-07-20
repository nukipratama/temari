import { router } from '@inertiajs/react';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';

interface EnableNotificationsModalProps {
    open: boolean;
    onClose: () => void;
}

/**
 * Soft front door behind the muted "Kirim notifikasi" pill for a user who has no
 * channel wired yet. Channel-neutral on purpose: notifikasi HP and Telegram both
 * live on Pengaturan, so this points there instead of pushing one channel. Uses
 * the shared {@see TemariNudgeModal} shell.
 */
export default function EnableNotificationsModal({ open, onClose }: Readonly<EnableNotificationsModalProps>) {
    return (
        <TemariNudgeModal
            open={open}
            onClose={onClose}
            title="Nyalain notifikasi dulu yuk"
            body={
                <>
                    Notifikasimu belum nyala. Begitu nyala, tiap abis lari sama pas rekap aku kabarin
                    langsung, jadi gak bakal kelewat. Bisa lewat notifikasi HP atau Telegram, pilih aja.
                </>
            }
            primaryLabel="Ke Pengaturan"
            primaryIcon="mdi:bell-outline"
            onPrimary={() => router.visit('/pengaturan')}
        />
    );
}
