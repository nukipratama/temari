<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Static bot reply copy in Temari's voice (casual aku/kamu, no em-dashes, sparse
 * emoji). Kept in one place so a voice review is a single file. See
 * docs/voice-and-tone.md.
 */
class TelegramReplies
{
    /**
     * Sent on a successful link. Names the account ($name, the Strava display
     * name) so the user confirms which TemanLari account this Telegram is tied
     * to.
     */
    public static function welcome(string $name): string
    {
        return "Halo {$name}! Aku Temari. Telegram ini sekarang nyambung ke akun TemanLari kamu. "
            . 'Mulai sekarang, tiap abis lari sama pas rekap mingguan, aku kabarin ke sini ya. 🎉';
    }

    public static function expired(): string
    {
        return 'Yah, link-nya udah gak berlaku (kedaluwarsa atau udah kepakai). Buka lagi halaman profil '
            . 'di TemanLari terus tap "Hubungkan Telegram" ya, nanti aku kasih link baru.';
    }

    public static function generic(): string
    {
        return 'Halo! Aku Temari. Buka TemanLari terus tap "Hubungkan Telegram" biar kita nyambung ya.';
    }

    public static function disconnected(): string
    {
        return 'Oke, Telegram kamu udah aku lepas dari akun TemanLari. Kapanpun mau nyambung lagi, aku tetap di sini.';
    }

    /** Sent by the "Kirim notifikasi tes" button on the Aku page. */
    public static function test(): string
    {
        return '🔔 Tes notifikasi dari Temari. Kalau kamu lihat ini, sambungan Telegram kamu udah jalan. '
            . 'Nanti tiap abis lari sama rekap mingguan aku kabarin ke sini ya.';
    }
}
