/**
 * Beginner-friendly explanations for every sport-science term surfaced
 * across the app. Each entry is a 1-2 sentence Indonesian explanation
 * keyed by a stable slug. Components opt in via `<MetricExplainer
 * metricKey="ctl" />` next to the label they want to demystify.
 *
 * Voice matches the Temari persona — informal "lo" / "aku", running
 * domain terms stay English, no em-dash, no markdown.
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
        body: 'Kebugaran rata-rata 42 hari terakhir. Makin tinggi makin siap lari jauh atau intens. Naik pelan kalau lo konsisten.',
    },
    atl: {
        acronym: 'ATL',
        label: 'Fatigue',
        body: 'Beban 7 hari terakhir. Tinggi artinya lo baru habis kerja keras, perlu recovery sebelum push lagi.',
    },
    form: {
        label: 'Form',
        body: 'Selisih Fitness minus Fatigue. Positif berarti segar dan siap, negatif berarti lagi capek dari sesi keras.',
    },
    trimp: {
        acronym: 'TRIMP',
        label: 'TRIMP',
        body: 'Skor usaha satu sesi lari. Gabungan durasi sama detak jantung — makin lama atau makin keras, makin tinggi skornya.',
    },
    monotony: {
        label: 'Monotony',
        body: 'Variasi intensitas mingguan. Di atas 2 artinya minggu lo terlalu seragam, riskan cedera. Selipin easy day buat turunin angka ini.',
    },
    strain: {
        label: 'Strain',
        body: 'Total tekanan minggu ini, hitung dari TRIMP dikali Monotony. Strain tinggi sinyal beban menumpuk.',
    },
    decoupling: {
        label: 'Decoupling',
        body: 'Selisih efisiensi paruh pertama sama paruh kedua lari. Di atas 5% artinya HR drift, base aerobic belum solid atau lo udah kepayahan.',
    },
    vibe: {
        label: 'Vibe',
        body: 'Ringkasan kondisi lo hari ini, diambil dari Form sama tren mingguan. Aku pakai ini buat nentuin tone briefing.',
    },
    cadence: {
        label: 'Cadence',
        body: 'Langkah per menit (spm). 170 sampai 180 lazim buat distance runner, naik 5 sampai 10 pas sprint.',
    },
    edwards_trimp: {
        acronym: 'Edwards',
        label: 'Edwards TRIMP',
        body: 'Metode hitung TRIMP-nya pakai bobot per HR zone. Z1 dapet 1 poin/menit, Z5 dapet 5 poin/menit. Skor lebih tinggi = sesi lebih keras.',
    },
    hr_zones: {
        label: 'HR Zones',
        body: 'Lima tingkat intensitas berdasar detak jantung. Z1 paling santai, Z5 paling keras. Distribusinya nentuin tipe sesi lo.',
    },
    hr_z1: {
        acronym: 'Z1',
        label: 'Zone 1 — Recovery',
        body: 'Santai banget, bisa nyanyi sambil lari. Buat recovery atau cooldown.',
    },
    hr_z2: {
        acronym: 'Z2',
        label: 'Zone 2 — Conversational',
        body: 'Conversational pace — bisa ngobrol sambil lari. Zone andalan buat base building.',
    },
    hr_z3: {
        acronym: 'Z3',
        label: 'Zone 3 — Tempo',
        body: 'Tempo pace. Udah ngos-ngosan, masih bisa ngomong 1 sampai 2 kata aja.',
    },
    hr_z4: {
        acronym: 'Z4',
        label: 'Zone 4 — Threshold',
        body: 'Threshold pace. Udah keras, ngomong singkat doang. Buat sesi tempo atau interval.',
    },
    hr_z5: {
        acronym: 'Z5',
        label: 'Zone 5 — Max',
        body: 'Sprint mode, gak bisa ngomong sama sekali. Cuma dipake di interval pendek.',
    },
    status_fresh: {
        label: 'Fresh',
        body: 'Lagi segar, siap latihan berat. Form positif, fatigue rendah.',
    },
    status_optimal: {
        label: 'Optimal',
        body: 'Pas — beban sama kebugaran balance. Sweet spot buat training konsisten.',
    },
    status_fatigued: {
        label: 'Fatigued',
        body: 'Lelah, perlu kurangin intensitas. Kasih easy day atau rest, biar fatigue turun.',
    },
    status_overreaching: {
        label: 'Overreaching',
        body: 'Diforsir kelewatan. Wajib rest beberapa hari sebelum lanjut, kalau gak risiko cedera atau sakit naik.',
    },
} as const satisfies Record<string, MetricGlossaryEntry>;

export type MetricKey = keyof typeof METRIC_GLOSSARY;
