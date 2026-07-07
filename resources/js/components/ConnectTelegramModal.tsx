import { router } from '@inertiajs/react';
import TemariNudgeModal from '@/components/temari/TemariNudgeModal';

interface ConnectTelegramModalProps {
    open: boolean;
    onClose: () => void;
}

/**
 * Soft front door behind the muted "Kirim ke Telegram" pill for a user who
 * hasn't linked Telegram yet. Explains the win and points them at Profil,
 * where the actual bot-deeplink connect flow lives. Uses the shared
 * {@see TemariNudgeModal} shell.
 */
export default function ConnectTelegramModal({ open, onClose }: Readonly<ConnectTelegramModalProps>) {
    return (
        <TemariNudgeModal
            open={open}
            onClose={onClose}
            title="Sambungin Telegram dulu yuk"
            body={
                <>
                    Telegram-mu belum nyambung. Begitu nyambung, tiap abis lari sama pas rekap mingguan aku
                    kabarin langsung ke HP kamu, jadi gak bakal kelewat.
                </>
            }
            primaryLabel="Sambungin Telegram"
            primaryIcon="mdi:telegram"
            onPrimary={() => router.visit('/profil')}
        />
    );
}
