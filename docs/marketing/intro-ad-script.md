---
title: Intro ad — voiceover script
description: Locked Indonesian voiceover script for the ~2min motion-graphics intro/promo ad (Temari narrates, first-person)
tags: [marketing, ad]
status: living
reviewed: 2026-06-28
---

# Intro ad — voiceover script

The narration for the ~2 minute motion-graphics intro/promo ad (Remotion visuals + Indonesian
voiceover + music). **Temari narrates in first person** — she is the runner's *friend*, not "an AI";
AI is named once, soft-sell, as the tool she uses (see the `project_temari_persona_friend_not_ai`
memory). Voice tone follows [[voice-and-tone]]: casual Bahasa Indonesia, no em-dashes, English only
for running terms (splits, HR zone, cadence, decoupling, PR, gear). Part of [[marketing/index|Marketing]].

## Shipped form
The final ad is the **"The Run"** rebuild: an all-native Remotion motion-graphics piece (one camera
travels a glowing route through a dawn world; Temari runs past each feature as a checkpoint; UI is
re-rendered natively, not screenshotted). It is rendered to a web-optimized `public/videos/intro.mp4`
(plus `public/videos/intro-poster.jpg`) and shown as the hero video, poster + click-to-play, on the
login page: [resources/js/pages/Auth/Login.tsx](resources/js/pages/Auth/Login.tsx). The Remotion
source lives in the ad scratchpad and is not committed (flagged for later preservation if needed).

## Production settings
- **Engine:** ElevenLabs, generated manually on the website (free tier can't use library voices via API).
- **Voice:** Velora (native Indonesian). **Model:** Eleven v3. **Stability:** Creative. Speaker boost on.
- **Tags:** inline `[curious]` `[cheerfully]` `[warmly]` `[excited]` `[happy]` `[softly]` `[thoughtful]`
  `[chuckles]` `[short pause]` are v3 audio tags (delivery cues), kept moderate so Creative stability
  doesn't swing. `...` ellipses = pauses (NOT `<break>`, which reads poorly). CAPS = emphasis. The
  `[short pause]` / `[thoughtful]` / `[chuckles]` cues were added during an ElevenLabs preview pass.
- Working copy for the render pipeline lives in the ad scratchpad as `vo_script_final.txt`.

## Scene → feature map
1. Strava hook + Temari intro (soft AI) · 2. AI narrator (briefing / daily greeting / post-run, chained)
· 3. Run detail (map, splits, HR zone, cadence, decoupling, technique + pacing, Past You) · 4. Cards
(unboxing reveal, special move, badge, rarity, **sharing**) · 5. The vibes / run moods · 6. Calendar +
weekly/monthly recap + Fondasi/Kelelahan/Kesiapan · 7. PR context + targets + gear unlock (**dress up
Temari**) · 8. Telegram push · 9. CTA.

## Script

```
[curious] Kamu lari rutin... [short pause] tapi habis itu? Datamu cuma numpuk di Strava. Angka doang, tanpa arti. [cheerfully] Kenalin, namaku Temari, yang bakal nemenin larimu. Tinggal connect Strava, dan aku bakal bikin tiap larimu jadi lebih berarti...

[warmly] Tiap data larimu aku baca, terus aku ceritain pakai bahasa kamu sendiri. [thoughtful] Dibantu AI, biar nggak ada detail yang kelewat, dan tiap cerita pas buat kamu. [cheerfully] Dari briefing pagi, sapaan harian, sampai cerita tiap habis lari. Semua personal, dan nyambung tiap hari...

[excited] Pengen ngerti larimu lebih dalam? Buka satu lari, semuanya kebuka. [chuckles] Map rute, splits per km, HR zone, cadence, sampai decoupling. [warmly] Aku bedah teknik sama pacing kamu, terus aku bandingin sama kamu yang dulu. Berasa punya pelatih pribadi...

[excited] Biar lari nggak ngebosenin, tiap larimu aku ubah jadi kartu koleksi! [happy] Buka bungkusnya... ada special move, badge, sama rarity, dari Biasa sampai LEGENDARIS. Makin spesial larimu, makin langka kartunya. [cheerfully] Pamerin ke temen-temenmu, atau kumpulin yang paling langka...

[cheerfully] Aku juga ngerti mood larimu. Dari Lincah, Membara, sampai pas lagi Loyo, aku nemenin sesuai kondisi kamu...

[warmly] Tiap lari ngewarnain kalendermu. Tiap minggu sama bulan, aku bikinin rekap, lengkap sama Fondasi, Kelelahan, sama Kesiapan kamu. [cheerfully] Jadi kamu tau kapan harus gas... kapan harus santai...

[excited] Pecah PR baru? Langsung aku jelasin konteksnya! [cheerfully] Makin konsisten, makin banyak target kelar, makin banyak gear yang kebuka, buat kamu dandanin aku sesukamu. [happy] Larimu kerasa naik level beneran...

[curious] Sibuk, gak sempet buka app? [warmly] Tenang. Cerita lari sama rekap mingguan langsung aku kirim ke Telegram kamu...

[cheerfully] Jadi, yuk mulai lari. [softly] Karena larimu... gak harus sendirian, dan gak harus tanpa arti. [happy] Lari bareng aku. Aku Temari!
```
