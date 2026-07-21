import { router } from "@inertiajs/react";
import { Icon } from "@iconify/react";
import { AnimatePresence, motion } from "framer-motion";
import { useCallback, useEffect, useRef, useState } from "react";
import ConfettiBurst from "@/components/ConfettiBurst";
import HeroPanel from "@/components/ui/HeroPanel";
import Kartu from "./Kartu";
import PackWrapper from "./PackWrapper";
import PillButton from "@/components/ui/PillButton";
import ShareCardModal from "./ShareCardModal";
import type { ShareKartuData } from "@/lib/shareCard";
import { useFocusTrap } from "@/hooks/useFocusTrap";
import { useBodyScrollLock } from "@/hooks/useBodyScrollLock";
import Temari from "@/components/temari/Temari";
import { type TemariPose } from "@/components/temari/TemariProto";
import { RARITY_HEX, RARITY_LABELS, badgeEmblem, badgeName, buildCardStats, paceShapeFromDetail, zonePctFromDetail } from "@/lib/runcard";
import { formatDuration, formatKm, formatPace, paceSecPerKm } from "@/lib/pace";
import { csrfToken } from "@/lib/http";
import type { ActivityDetail, PendingReveal, Rarity } from "@/types/inertia";

interface CardRevealProps {
  pending: PendingReveal;
}

const THEATRICAL_RARITIES: ReadonlyArray<Rarity> = [
  "rare",
  "epic",
  "legendary",
];

/** Mascot pose based on state (not frame-based). */
function revealPose(opened: boolean, rarity: Rarity): TemariPose {
  if (!opened) return "excited";
  if (rarity === "legendary") return "glow";
  if (THEATRICAL_RARITIES.includes(rarity)) return "pumped";
  return "proud";
}

export default function CardReveal({
  pending,
}: Readonly<CardRevealProps>) {
  const theatrical = THEATRICAL_RARITIES.includes(pending.rarity);
  const rarityHex = RARITY_HEX[pending.rarity];
  const [confettiKey, setConfettiKey] = useState<string | null>(null);
  const [shareOpen, setShareOpen] = useState(false);
  const buttonTimerRef = useRef<ReturnType<typeof setTimeout>>(null);
  // The card starts wrapped in foil; reduced-motion users skip straight to it
  // (and therefore to its action buttons, since openPack never runs for them).
  const prefersReducedMotion = () =>
    globalThis.matchMedia?.("(prefers-reduced-motion: reduce)").matches === true;
  const [opened, setOpened] = useState(prefersReducedMotion);
  const [showButtons, setShowButtons] = useState(prefersReducedMotion);
  const [dismissed, setDismissed] = useState(false);
  const sentRef = useRef(false);
  const panelRef = useRef<HTMLDivElement>(null);

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
    setDismissed(true);
    void markSeen().then(() => router.reload({ only: ["pendingReveal"] }));
  }, [markSeen]);

  // Await markSeen before navigating — prevents the Inertia request from
  // arriving before the seen POST clears pending_reveal_card_id.
  const viewKoleksi = useCallback((): void => {
    void markSeen().then(() =>
      router.visit("/kartu", { preserveScroll: false }),
    );
  }, [markSeen]);

  // Tearing the pack reveals the card and fires the confetti burst (theatrical
  // reveals only).
  const openPack = useCallback(() => {
    setOpened(true);
    if (theatrical) {
      setConfettiKey(`reveal-${pending.card_id}`);
    }
    // Stagger button appearance after card animation settles (~600ms).
    buttonTimerRef.current = setTimeout(() => setShowButtons(true), 600);
  }, [theatrical, pending.card_id]);

  // Clean up button timer on unmount.
  useEffect(() => {
    return () => {
      if (buttonTimerRef.current !== null) clearTimeout(buttonTimerRef.current);
    };
  }, []);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      // When the share modal is open it owns Escape (closes only itself), so
      // the keystroke doesn't also dismiss the whole reveal beneath it.
      if (e.key === "Escape" && !shareOpen) {
        e.preventDefault();
        dismiss();
      }
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  }, [dismiss, shareOpen]);

  useFocusTrap(!dismissed, panelRef);
  useBodyScrollLock(!dismissed);

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

  const effectivePose = revealPose(opened, pending.rarity);
  const eyebrow = opened ? `★ ${RARITY_LABELS[pending.rarity]}` : "Sync masuk";
  const title = opened ? pending.special_move : "Aku lagi baca lari kamu…";
  const subtitleText = opened ? "Udah masuk koleksimu." : undefined;

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
    shareUrl: pending.public_share_url,
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
    distanceKm: pending.distance_m != null ? pending.distance_m / 1000 : null,
    edition: pending.edition ?? null,
  };

  // Optimistic close: hide instantly on dismiss; the seen-POST + reload run in
  // the background (see `dismiss`). Covers Tutup, outside-click, and Escape.
  if (dismissed) return null;

  return (
    <>
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label="Kartu baru"
        className="fixed inset-0 z-50 flex justify-center overflow-y-auto overflow-x-clip bg-sky-deep/80 px-4 py-6 backdrop-blur-md"
        onClick={() => { if (opened) dismiss(); }}
      >
        <ConfettiBurst burstKey={confettiKey} />
        <motion.div
          initial={{ opacity: 0, scale: 0.96, y: 12 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
          onClick={(e) => e.stopPropagation()}
          className="my-auto w-full max-w-3xl"
        >
          <HeroPanel className="px-8 py-10 sm:px-12 sm:py-12">
            <div className="grid grid-cols-1 items-center gap-9 lg:grid-cols-[200px_1fr]">
              {/* Mascot */}
              <div className="flex justify-center">
                {!opened ? (
                  <Temari
                    pose="proud"
                    size={200}
                    animate
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

              {/* Content */}
              <div>
                <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                  {eyebrow}
                </div>
                <h2 className="font-display text-display-sm text-cream">
                  <em className="italic text-horizon">{title}</em>
                </h2>
                {subtitleText && (
                  <p className="mt-4 font-display text-base italic leading-relaxed text-cream/80 sm:text-lg">
                    {subtitleText}
                  </p>
                )}

                {/* Card — always rendered behind foil, revealed on open */}
                <motion.div
                  initial={{ opacity: 0, scale: 0.8, rotate: -4 }}
                  animate={opened
                    ? { opacity: 1, scale: 1, rotate: -2 }
                    : { opacity: 1, scale: 0.95, rotate: 0 }}
                  transition={opened
                    ? { type: "spring", stiffness: 260, damping: 18, delay: 0.1 }
                    : { duration: 0 }}
                  className="relative mt-6 max-w-md mx-auto lg:mx-0"
                >
                  {/* Ignition ring on open */}
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
                  {/* Legendary light flash */}
                  <AnimatePresence>
                    {opened && pending.rarity === "legendary" && (
                      <motion.div
                        key="flash"
                        initial={{ opacity: 0.7 }}
                        animate={{ opacity: 0 }}
                        transition={{ duration: 0.8 }}
                        className="pointer-events-none absolute inset-0 rounded-[16px] bg-white/50"
                      />
                    )}
                  </AnimatePresence>
                  <Kartu
                    name={pending.special_move}
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
                  {/* Foil wrapper — torn away on open */}
                  <AnimatePresence>
                    {!opened && (
                      <PackWrapper
                        key="pack"
                        rarity={pending.rarity}
                        onOpen={openPack}
                      />
                    )}
                  </AnimatePresence>
                </motion.div>

                {/* Action buttons — stagger in after reveal */}
                <div className="mt-7 flex flex-wrap items-center gap-2.5">
                  {showButtons && opened && (
                    <>
                      <motion.div
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0 }}
                      >
                        <PillButton tone="sky" onClick={viewKoleksi}>
                          <Icon icon="mdi:cards-outline" width={16} height={16} aria-hidden />
                          Lihat koleksi
                        </PillButton>
                      </motion.div>
                      <motion.div
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.1 }}
                      >
                        <PillButton
                          tone="sky"
                          onClick={(e) => {
                            e.stopPropagation();
                            setShareOpen(true);
                          }}
                        >
                          <Icon icon="mdi:share-variant" width={16} height={16} aria-hidden />
                          Bagikan
                        </PillButton>
                      </motion.div>
                    </>
                  )}
                  <PillButton tone="ghost" onSky size="sm" onClick={dismiss}>
                    <Icon icon="mdi:close" width={14} height={14} aria-hidden />
                    Tutup
                  </PillButton>
                </div>

                {/* Helper text */}
                <div className="mt-4 text-label-micro text-ink-on-sky">
                  {!opened
                    ? "Sobek bungkusnya buat lihat kartu"
                    : "Udah masuk koleksimu · ketuk di luar untuk tutup"}
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
  // Don't double-append pace if detail_name already contains pace-like info.
  if (/\d+:\d+.*km/i.test(pending.detail_name)) return pending.detail_name;
  const paceSec = paceSecPerKm(pending.moving_time_sec, pending.distance_m);
  if (paceSec === null) return pending.detail_name;
  return `${pending.detail_name} · ${formatPace(paceSec)}/km`;
}
