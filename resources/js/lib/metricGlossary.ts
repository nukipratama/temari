/**
 * Beginner-friendly explanations for every sport-science term surfaced
 * across the app. Each entry is a 1-2 sentence Indonesian explanation
 * keyed by a stable slug. Components opt in via `<MetricExplainer
 * metricKey="ctl" />` next to the label they want to demystify.
 *
 * Voice matches the Temari persona: santai khas obrolan, "kamu" address form,
 * common running terms stay English, obscure ones get explained, no em-dash,
 * no markdown. See docs/voice-and-tone.md.
 */

export interface MetricGlossaryEntry {
    /** The shorthand label/acronym the user actually sees on the surface (e.g., "CTL", "Z2"). */
    acronym?: string;
    /** Human-readable name. Used as the popover heading. */
    label: string;
    /** 1-2 sentence Indonesian explanation. Plain prose, no markdown. */
    body: string;
}

export const METRIC_GLOSSARY = {
    ctl: {
        acronym: 'CTL',
        label: 'Fitness',
        body: 'Kebugaran rata-rata 42 hari terakhir. Semakin tinggi, semakin siap kamu untuk lari jauh atau intens. Naiknya perlahan kalau kamu konsisten.',
    },
    atl: {
        acronym: 'ATL',
        label: 'Fatigue',
        body: 'Beban 7 hari terakhir. Tinggi berarti kamu baru selesai kerja keras dan perlu recovery sebelum push lagi.',
    },
    form: {
        label: 'Kesiapan',
        body: 'Seberapa siap badanmu buat lari hari ini, dari selisih Fitness dikurangi Fatigue. Positif berarti segar dan siap. Negatif belum tentu jelek: artinya kamu lagi nyimpen capek tapi masih di zona ideal buat adaptasi.',
    },
    trimp: {
        acronym: 'TRIMP',
        label: 'TRIMP',
        body: 'Skor usaha satu sesi lari. Gabungan durasi dan detak jantung. Semakin lama atau semakin keras, semakin tinggi skornya.',
    },
    monotony: {
        label: 'Monotony',
        body: 'Variasi intensitas mingguan. Di atas 2 berarti minggumu terlalu seragam dan rentan cedera. Selipkan easy day untuk menurunkan angka ini.',
    },
    strain: {
        label: 'Strain',
        body: 'Total tekanan minggu ini, dihitung dari TRIMP dikalikan Monotony. Strain tinggi menandakan beban menumpuk.',
    },
    decoupling: {
        label: 'Decoupling',
        body: 'Selisih efisiensi paruh pertama dan paruh kedua lari. Di atas 5% berarti HR drift, base aerobic belum solid atau kamu sudah kepayahan.',
    },
    vibe: {
        label: 'Vibe',
        body: 'Ringkasan kondisi kamu hari ini, diambil dari Form dan tren mingguan. Aku pakai ini untuk menentukan tone briefing.',
    },
    cadence: {
        label: 'Cadence',
        body: 'Langkah per menit (spm). Rentang 170 sampai 180 lazim untuk distance runner, biasanya naik 5 sampai 10 saat sprint.',
    },
    edwards_trimp: {
        acronym: 'Edwards',
        label: 'Edwards TRIMP',
        body: 'Metode hitung TRIMP yang menggunakan bobot per HR zone. Z1 dapat 1 poin per menit, Z5 dapat 5 poin per menit. Skor lebih tinggi berarti sesi lebih keras.',
    },
    hr_zones: {
        label: 'HR Zones',
        body: 'Lima tingkat intensitas berdasarkan detak jantung. Z1 paling santai, Z5 paling keras. Distribusinya menentukan tipe sesi kamu.',
    },
    hr_z1: {
        acronym: 'Z1',
        label: 'Zone 1: Recovery',
        body: 'Sangat santai, masih bisa bernyanyi sambil lari. Untuk recovery atau cooldown.',
    },
    hr_z2: {
        acronym: 'Z2',
        label: 'Zone 2: Conversational',
        body: 'Conversational pace, masih bisa mengobrol sambil lari. Zone andalan untuk base building.',
    },
    hr_z3: {
        acronym: 'Z3',
        label: 'Zone 3: Tempo',
        body: 'Tempo pace. Sudah terengah-engah, hanya bisa bicara satu sampai dua kata.',
    },
    hr_z4: {
        acronym: 'Z4',
        label: 'Zone 4: Threshold',
        body: 'Threshold pace. Sudah keras, hanya bicara singkat. Untuk sesi tempo atau interval.',
    },
    hr_z5: {
        acronym: 'Z5',
        label: 'Zone 5: Max',
        body: 'Mode sprint, tidak bisa bicara sama sekali. Dipakai hanya untuk interval pendek.',
    },
    status_fresh: {
        label: 'Lagi seger',
        body: 'Lagi segar dan siap buat sesi berat. Kesiapan positif, fatigue rendah.',
    },
    status_optimal: {
        label: 'Pas banget',
        body: 'Pas, beban dan kebugaran seimbang. Sweet spot buat training konsisten.',
    },
    status_fatigued: {
        label: 'Mulai capek',
        body: 'Lagi capek, intensitasnya dikurangi dulu. Kasih easy day atau rest biar fatigue turun.',
    },
    status_overreaching: {
        label: 'Kelewatan',
        body: 'Bebannya kelewat banyak. Wajib rest beberapa hari sebelum lanjut, kalau dipaksa risiko cedera atau sakit naik.',
    },
    vibe_vs_mood: {
        label: 'Vibe vs Mood',
        body: 'Vibe = kondisi kamu hari ini, dihitung dari fitness, fatigue, dan form. Mood = nuansa lari per sesi (nyala, enteng, oleng, lemes, mumet, adem). Vibe satu hari satu, mood per lari.',
    },
} as const satisfies Record<string, MetricGlossaryEntry>;

export type MetricKey = keyof typeof METRIC_GLOSSARY;
