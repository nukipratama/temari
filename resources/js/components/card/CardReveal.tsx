import { router } from "@inertiajs/react";
import { AnimatePresence, motion } from "framer-motion";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import ConfettiBurst from "@/components/ConfettiBurst";
import HeroPanel from "@/components/ui/HeroPanel";
import Kartu from "./Kartu";
import PackWrapper from "./PackWrapper";
import PillButton from "@/components/ui/PillButton";
import ShareCardModal from "./ShareCardModal";
import type { ShareKartuData } from "@/lib/shareCard";
import Temari from "@/components/temari/Temari";
import { type TemariPose } from "@/components/temari/TemariProto";
import TemariMascot from "@/components/temari/TemariMascot";
import { RARITY_HEX, RARITY_LABELS, badgeEmblem, badgeName, buildCardStats, paceShapeFromDetail, zonePctFromDetail } from "@/lib/runcard";
import { formatDuration, formatKm, formatPace, paceSecPerKm } from "@/lib/pace";
import { csrfToken } from "@/lib/http";
import type { ActivityDetail, PendingReveal, Rarity } from "@/types/inertia";

interface CardRevealProps {
  pending: PendingReveal;
  onPrMoment?: () => void;
}

interface Frame {
  pose: TemariPose;
  /** When true, render the full-body running mascot instead of the bunny.
   *  Reserved for the "Sync masuk" frame — Temari literally sprinting back
   *  from Strava with your run. Every other frame stays on the bunny so
   *  identity is consistent across reveal/voice/hero surfaces. */
  runner?: boolean;
  eyebrow: string;
  title: string;
  subtitle?: string;
  showKartu?: boolean;
  showConfetti?: boolean;
}

const THEATRICAL_RARITIES: ReadonlyArray<Rarity> = [
  "rare",
  "epic",
  "legendary",
];

function framesFor(theatrical: boolean, rarity: Rarity, name: string): Frame[] {
  const rarityLabel = RARITY_LABELS[rarity];
  if (!theatrical) {
    // Intimate flow: 2 frames for common/uncommon
    return [
      {
        pose: "reading",
        runner: true,
        eyebrow: "Sync masuk",
        title: "Aku lagi baca lari kamu…",
      },
      {
        pose: "holding",
        eyebrow: `Kartu baru · ${rarityLabel}`,
        title: name,
        subtitle: "Udah masuk koleksimu.",
        showKartu: true,
      },
    ];
  }

  // Theatrical flow: 3 frames for rare+ (sync → reveal → saved). The old
  // "Hasil" talking-head is gone; that excited beat now lives in the mascot's
  // anticipation while the card is still wrapped.
  return [
    {
      pose: "reading",
      runner: true,
      eyebrow: "Sync masuk",
      title: "Aku lagi baca lari kamu…",
    },
    {
      pose: "holding",
      eyebrow: `★ ${rarityLabel}`,
      title: name,
      showKartu: true,
      showConfetti: true,
    },
    {
      pose: "proud",
      eyebrow: "Disimpan",
      title: "Udah masuk koleksimu.",
      subtitle: "Tarik napas, lalu balik lagi ke larimu.",
      showKartu: true,
    },
  ];
}

/**
 * The mascot's pose for the current beat: excited anticipation while the pack is
 * still sealed, a rarity-keyed celebration once it's torn open (full glow for
 * legendary, an energetic bounce otherwise), else the frame's scripted pose.
 */
function revealPose(frame: Frame, wrapped: boolean, opened: boolean, rarity: Rarity): TemariPose {
  if (wrapped) {
    return "excited";
  }
  if (frame.showKartu && opened) {
    return rarity === "legendary" ? "glow" : "pumped";
  }
  return frame.pose;
}

export default function CardReveal({
  pending,
  onPrMoment,
}: Readonly<CardRevealProps>) {
  const theatrical = THEATRICAL_RARITIES.includes(pending.rarity);
  const rarityHex = RARITY_HEX[pending.rarity];
  const frames = useMemo(
    () => framesFor(theatrical, pending.rarity, pending.special_move),
    [theatrical, pending.rarity, pending.special_move],
  );

  const [step, setStep] = useState(0);
  const [confettiKey, setConfettiKey] = useState<string | null>(null);
  const [shareOpen, setShareOpen] = useState(false);
  // The card starts wrapped in foil; reduced-motion users skip straight to it.
  const [opened, setOpened] = useState(
    () => globalThis.matchMedia?.("(prefers-reduced-motion: reduce)").matches === true,
  );
  const sentRef = useRef(false);

  // /api/kartu/{card}/seen returns plain JSON, so Inertia's router can't
  // call it (it errors on non-Inertia responses).
  const markSeen = useCallback((): Promise<void> => {
    if (sentRef.current) return Promise.resolve();
    sentRef.current = true;
    return fetch(`/api/kartu/${pending.card_id}/seen`, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": csrfToken(),
        "X-Requested-With": "XMLHttpRequest",
      },
      body: "{}",
    })
      .then(() => {})
      .catch(() => {
        /* silent — next reload picks up server state */
      });
  }, [pending.card_id]);

  const dismiss = useCallback((): void => {
    // A replay re-watches an old card; don't re-fire the PR celebration.
    if (pending.is_pr && !pending.is_replay && onPrMoment) {
      onPrMoment();
    }
    // Await markSeen before reloading — same race guard as viewKoleksi.
    void markSeen().then(() => router.reload({ only: ["pendingReveal"] }));
  }, [markSeen, pending.is_pr, pending.is_replay, onPrMoment]);

  // Await markSeen before navigating — prevents the Inertia request from
  // arriving before the seen POST clears pending_reveal_card_id.
  const viewKoleksi = useCallback((): void => {
    void markSeen().then(() =>
      router.visit("/kartu", { preserveScroll: false }),
    );
  }, [markSeen]);

  const advance = useCallback(() => {
    setStep((s) => {
      if (s + 1 >= frames.length) {
        return s;
      }
      return s + 1;
    });
  }, [frames.length]);

  const frame = frames[step] ?? frames[frames.length - 1];
  const isLastFrame = step === frames.length - 1;
  // The card stays behind the foil PackWrapper until the user tears it open.
  const wrapped = frame.showKartu === true && !opened;
  // Temari reacts to the moment instead of holding one static pose: leaning in
  // while the pack is sealed, then celebrating once it's torn open.
  const effectivePose = revealPose(frame, wrapped, opened, pending.rarity);

  // Tearing the pack reveals the card and fires the confetti burst (theatrical
  // reveals only — the showConfetti frame flag marks them).
  const openPack = useCallback(() => {
    setOpened(true);
    if (frames[step]?.showConfetti) {
      setConfettiKey(`reveal-${pending.card_id}-${step}`);
    }
  }, [frames, step, pending.card_id]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        e.preventDefault();
        dismiss();
        return;
      }
      // No keyboard gesture unzips the pack (touch-first); block frame advance
      // while the card is still wrapped so the reveal isn't skipped.
      if (e.key === " " || e.key === "Enter" || e.key === "ArrowRight") {
        e.preventDefault();
        if (!wrapped) {
          advance();
        }
      }
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  }, [advance, dismiss, wrapped]);
  const km = formatKm(pending.distance_m);
  const durasi =
    pending.moving_time_sec != null
      ? formatDuration(pending.moving_time_sec)
      : "—";
  const trimp =
    pending.trimp_edwards != null
      ? Math.round(pending.trimp_edwards).toString()
      : "—";
  const subtitle = buildSubtitle(pending);
  const onPrimary = isLastFrame ? viewKoleksi : advance;
  // While wrapped, a backdrop tap shouldn't skip the reveal.
  const advanceOrDismiss = isLastFrame ? dismiss : advance;
  const onBackdrop = wrapped ? () => {} : advanceOrDismiss;

  const sharePaceSec = paceSecPerKm(pending.moving_time_sec, pending.distance_m);
  const shareBadges = (pending.badges ?? []).slice(0, 2);

  // Build a minimal ActivityDetail shell so the stream_summary helpers can
  // derive cadence, fastest km, and zone distribution from the reveal payload.
  const revealDetail: ActivityDetail = {
    id: 0,
    activity_id: pending.activity_id,
    name: pending.detail_name,
    start_date_local: null,
    distance: pending.distance_m,
    moving_time: pending.moving_time_sec,
    average_heartrate: pending.average_heartrate ?? null,
    trimp_edwards: pending.trimp_edwards,
    stream_summary: pending.stream_summary ?? null,
  };

  const revealStats = buildCardStats(revealDetail);
  const revealZonePct = zonePctFromDetail(revealDetail);
  const revealPaceShape = paceShapeFromDetail(revealDetail);

  const shareData: ShareKartuData = {
    id: pending.card_id,
    name: pending.special_move,
    rarity: pending.rarity,
    mood: pending.mood,
    subtitle,
    date: null,
    km,
    durasi,
    pace: sharePaceSec != null ? formatPace(sharePaceSec) : null,
    trimp,
    hr: revealStats.hr ?? null,
    cadence: revealStats.cadence ?? null,
    fastestKm: revealStats.fastestKm ?? null,
    zonePct: revealZonePct,
    location: null,
    weather: null,
    tags: shareBadges.map(badgeName),
    tagEmojis: shareBadges.map(badgeEmblem),
    quote: null,
    polyline: pending.summary_polyline ?? null,
    edition: pending.edition ?? null,
  };

  return (
    <>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Kartu baru"
        className="fixed inset-0 z-50 flex items-center justify-center bg-sky-deep/80 px-4 backdrop-blur-md"
        onClick={onBackdrop}
      >
        <ConfettiBurst burstKey={confettiKey} />
        <motion.div
          key={step}
          initial={{ opacity: 0, scale: 0.96, y: 12 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
          onClick={(e) => e.stopPropagation()}
          className="w-full max-w-3xl"
        >
          <HeroPanel className="px-8 py-10 sm:px-12 sm:py-12">
            <div className="grid items-center gap-9 lg:grid-cols-[200px_1fr]">
              <div className="flex justify-center">
                {frame.runner ? (
                  <TemariMascot
                    mood="nyala"
                    sizeClass="h-[200px] w-[200px]"
                    idle="mood"
                  />
                ) : (
                  <motion.div
                    key={effectivePose}
                    initial={{ scale: 0.94 }}
                    animate={{ scale: 1 }}
                    transition={{ type: "spring", stiffness: 320, damping: 14 }}
                  >
                    <Temari pose={effectivePose} size={200} />
                  </motion.div>
                )}
              </div>
              <div>
                <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                  {frame.eyebrow}
                </div>
                <h2 className="font-display text-display-sm text-cream">
                  <em className="italic text-horizon">{frame.title}</em>
                </h2>
                {frame.subtitle && (
                  <p className="mt-4 font-display text-base italic leading-relaxed text-cream/80 sm:text-lg">
                    {frame.subtitle}
                  </p>
                )}
                {frame.showKartu && (
                  <motion.div
                    initial={{ opacity: 0, rotate: -6, y: 12 }}
                    animate={{ opacity: 1, rotate: -3, y: 0 }}
                    transition={{ duration: 0.6, delay: 0.15 }}
                    className="relative mt-6 max-w-md"
                  >
                    {/* The card pops with a springy scale-overshoot the instant the
                        foil tears, and a one-shot rarity ring ignites around it. */}
                    <motion.div
                      animate={{ scale: opened ? 1 : 0.95 }}
                      transition={opened ? { type: "spring", stiffness: 300, damping: 15 } : { duration: 0 }}
                    >
                      <AnimatePresence>
                        {opened && (
                          <motion.span
                            key="ignite"
                            data-testid="card-ignite"
                            aria-hidden
                            initial={{ opacity: 0.85, scale: 0.96 }}
                            animate={{ opacity: 0, scale: 1.18 }}
                            transition={{ duration: 0.7, ease: "easeOut" }}
                            className="pointer-events-none absolute inset-0 rounded-[16px]"
                            style={{ boxShadow: `0 0 0 3px ${rarityHex}, 0 0 36px 8px ${rarityHex}` }}
                          />
                        )}
                      </AnimatePresence>
                      <Kartu
                        name={pending.special_move}
                        subtitle={subtitle}
                        km={km}
                        durasi={durasi}
                        trimp={trimp}
                        rarity={pending.rarity}
                        mood={pending.mood}
                        badges={(pending.badges ?? []).slice(0, 3)}
                        stats={revealStats}
                        zonePct={revealZonePct}
                        polyline={pending.summary_polyline}
                        paceShape={revealPaceShape}
                        edition={pending.edition}
                        size="lg"
                      />
                    </motion.div>
                    <AnimatePresence>
                      {wrapped && (
                        <PackWrapper
                          key="pack"
                          rarity={pending.rarity}
                          onOpen={openPack}
                        />
                      )}
                    </AnimatePresence>
                  </motion.div>
                )}
                <div className="mt-7 flex flex-wrap items-center gap-2.5">
                  {!wrapped && (
                    <PillButton tone="horizon" onClick={onPrimary}>
                      {isLastFrame ? "Lihat koleksi" : "Lanjut"}
                    </PillButton>
                  )}
                  {isLastFrame && frame.showKartu && opened && (
                    <PillButton
                      tone="horizon"
                      onClick={(e) => {
                        e.stopPropagation();
                        setShareOpen(true);
                      }}
                    >
                      Bagikan
                    </PillButton>
                  )}
                  <PillButton tone="ghost" onSky size="sm" onClick={dismiss}>
                    {isLastFrame ? "Tutup" : "Lewati"}
                  </PillButton>
                </div>
                <div className="mt-4 text-label-micro text-ink-on-sky">
                  {wrapped
                    ? "Sobek bungkusnya buat lihat kartu"
                    : `Frame ${step + 1} / ${frames.length} · ketuk untuk lanjut`}
                </div>
              </div>
            </div>
          </HeroPanel>
        </motion.div>
      </div>
      <ShareCardModal
        kartu={shareOpen ? shareData : null}
        onClose={() => setShareOpen(false)}
      />
    </>
  );
}

function buildSubtitle(pending: PendingReveal): string | null {
  if (pending.detail_name === null) return null;
  const paceSec = paceSecPerKm(pending.moving_time_sec, pending.distance_m);
  if (paceSec === null) return pending.detail_name;
  return `${pending.detail_name} · ${formatPace(paceSec)}/km`;
}
