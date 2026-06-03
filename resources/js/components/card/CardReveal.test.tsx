import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import CardReveal from "./CardReveal";
import type { PendingReveal } from "@/types/inertia";

const reload = vi.fn();
const visit = vi.fn();

vi.mock("@inertiajs/react", async () => {
  const actual: typeof import("@inertiajs/react") =
    await vi.importActual("@inertiajs/react");
  return {
    ...actual,
    // CardReveal renders <Temari>, which reads usePage().props; the real
    // hook needs an Inertia app context this test doesn't bootstrap.
    usePage: () => ({ props: {}, url: "/" }),
    router: {
      reload: (...args: unknown[]) => reload(...args),
      visit: (...args: unknown[]) => visit(...args),
    },
  };
});

const epicReveal: PendingReveal = {
  card_id: 42,
  activity_id: 99,
  rarity: "epic",
  special_move: "Pembalik Keadaan",
  mood: "nyala",
  badges: ["negative_split", "hari_panas"],
  detail_name: "10K race-pace",
  distance_m: 10000,
  moving_time_sec: 3480,
  trimp_edwards: 161,
  is_pr: false,
  pr_category_label: null,
  pr_time_display: null,
};

const commonReveal: PendingReveal = {
  card_id: 7,
  activity_id: 12,
  rarity: "common",
  special_move: "Pagi Santai",
  mood: "adem",
  badges: null,
  detail_name: "Easy run",
  distance_m: 5000,
  moving_time_sec: 1800,
  trimp_edwards: 42,
  is_pr: false,
  pr_category_label: null,
  pr_time_display: null,
};

const fetchMock = vi.fn(() =>
  Promise.resolve(new Response('{"seen":true}', { status: 200 })),
);

beforeEach(() => {
  reload.mockClear();
  visit.mockClear();
  fetchMock.mockClear();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("CardReveal", () => {
  it("renders the first frame eyebrow + title on mount", () => {
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByText("Sync masuk")).toBeInTheDocument();
    expect(screen.getByText(/Aku lagi baca lari kamu/)).toBeInTheDocument();
  });

  it("uses a 3-frame theatrical flow for epic+", () => {
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByText(/Frame 1 \/ 3/)).toBeInTheDocument();
  });

  it("uses a 2-frame intimate flow for common rarity", () => {
    render(<CardReveal pending={commonReveal} />);
    expect(screen.getByText(/Frame 1 \/ 2/)).toBeInTheDocument();
  });

  it("advances from the sync frame straight to the wrapped reveal", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByText(/Frame 1 \/ 3/)).toBeInTheDocument();
    await u.click(screen.getByText("Lanjut"));
    // Frame 2 is the reveal — the card arrives wrapped (counter hidden).
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
  });

  it('on the final frame, "Lihat koleksi" marks seen and navigates to /kartu', async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={commonReveal} />);
    await u.click(screen.getByText("Lanjut"));
    // Last frame for common (frame 2 / 2) — tear the pack open first.
    await u.click(screen.getByTestId("pack-wrapper"));
    await u.click(screen.getByText("Lihat koleksi"));

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/kartu/7/seen",
      expect.objectContaining({ method: "POST" }),
    );
    expect(visit).toHaveBeenCalledWith("/kartu", expect.anything());
  });

  it("Skip on any non-final frame marks seen and reloads the pendingReveal prop", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    await u.click(screen.getByText("Lewati"));

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/kartu/42/seen",
      expect.objectContaining({ method: "POST" }),
    );
    expect(reload).toHaveBeenCalledWith({ only: ["pendingReveal"] });
  });

  it("fires onPrMoment on dismiss for a fresh PR reveal", async () => {
    const onPrMoment = vi.fn();
    render(
      <CardReveal
        pending={{ ...epicReveal, is_pr: true }}
        onPrMoment={onPrMoment}
      />,
    );
    await userEvent.setup().keyboard("{Escape}");
    expect(onPrMoment).toHaveBeenCalledTimes(1);
  });

  it("suppresses onPrMoment when the PR reveal is a replay", async () => {
    const onPrMoment = vi.fn();
    render(
      <CardReveal
        pending={{ ...epicReveal, is_pr: true, is_replay: true }}
        onPrMoment={onPrMoment}
      />,
    );
    await userEvent.setup().keyboard("{Escape}");
    expect(onPrMoment).not.toHaveBeenCalled();
  });

  it("Escape key dismisses the reveal modal", async () => {
    render(<CardReveal pending={epicReveal} />);
    await userEvent.setup().keyboard("{Escape}");
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/kartu/42/seen",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("Space advances from the sync frame to the wrapped card", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByText(/Frame 1 \/ 3/)).toBeInTheDocument();
    await u.keyboard(" "); // → frame 2, the wrapped card (counter hidden)
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
  });

  it("does not advance past the wrapped card frame via keyboard (touch-first tear)", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    await u.keyboard(" "); // → frame 2 (wrapped card)
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    await u.keyboard("{ArrowRight}"); // blocked while wrapped
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    expect(screen.queryByText("Disimpan")).toBeNull(); // never reached the proud frame
  });

  it("ignites the card with a rarity ring when the pack is torn", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    await u.click(screen.getByText("Lanjut")); // → wrapped reveal frame
    expect(screen.queryByTestId("card-ignite")).toBeNull();
    await u.click(screen.getByTestId("pack-wrapper")); // tear it open
    expect(screen.getByTestId("card-ignite")).toBeInTheDocument();
  });

  it("only POSTs /seen once even if the user double-clicks Skip", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    const skip = screen.getByText("Lewati");
    await u.click(skip);
    await u.click(skip);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("renders the card with real km/duration/trimp values on the last frame", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={commonReveal} />);
    await u.click(screen.getByText("Lanjut")); // jump to last frame
    // 5000m → 5.00 km, 1800s → 30 menit, trimp 42 shown as the TRIMP badge number
    expect(screen.getByText("5.00")).toBeInTheDocument();
    expect(screen.getByText("30 menit")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
  });

  it("shows Bagikan button after the card is revealed and opens the share modal", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    // Epic = 3 frames: reading → holding (wrapped card) → proud.
    await u.click(screen.getByText("Lanjut"));
    // Frame 2 (holding): card is wrapped — tear it, then advance to the last frame.
    await u.click(screen.getByTestId("pack-wrapper"));
    await u.click(screen.getByText("Lanjut"));
    // Last frame: "Bagikan" button appears
    expect(screen.getByRole("button", { name: /Bagikan/ })).toBeInTheDocument();
    await u.click(screen.getByRole("button", { name: /Bagikan/ }));
    // Share modal opens
    expect(screen.getByText(/Bagikan kartu/)).toBeInTheDocument();
    // Close the modal (covers () => setShareOpen(false))
    await u.click(screen.getByLabelText("Tutup"));
  });

  it("keeps the card wrapped until torn, then reveals it on tap", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={commonReveal} />);
    await u.click(screen.getByText("Lanjut")); // → last frame, card wrapped
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    expect(screen.queryByText("Lihat koleksi")).toBeNull();
    await u.click(screen.getByTestId("pack-wrapper"));
    // Tearing reveals the card actions; the wrapper then animates away
    // (its unmount is an exit transition, so we assert the synchronous outcome).
    expect(screen.getByText("Lihat koleksi")).toBeInTheDocument();
  });

  it("skips the wrapper entirely under prefers-reduced-motion", async () => {
    vi.stubGlobal("matchMedia", () => ({ matches: true }));
    const u = userEvent.setup();
    render(<CardReveal pending={commonReveal} />);
    await u.click(screen.getByText("Lanjut")); // → last frame
    expect(screen.queryByTestId("pack-wrapper")).toBeNull();
    expect(screen.getByText("Lihat koleksi")).toBeInTheDocument();
  });
});
