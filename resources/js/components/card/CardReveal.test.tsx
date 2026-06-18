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
  edition: { index: 3, total: 7 },
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
  edition: { index: 1, total: 1 },
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
  it("renders the sealed eyebrow + title on mount", () => {
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByText("Sync masuk")).toBeInTheDocument();
    expect(screen.getByText(/Aku lagi baca lari kamu/)).toBeInTheDocument();
  });

  it("makes the dialog vertically scrollable so CTAs stay reachable on short viewports", () => {
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByRole("dialog").className).toContain("overflow-y-auto");
  });

  it("mounts the card wrapped in foil for theatrical (epic+) reveals", () => {
    render(<CardReveal pending={epicReveal} />);
    // No reveal happens until the pack is torn.
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    expect(screen.getByText("Sync masuk")).toBeInTheDocument();
    expect(screen.queryByTestId("card-ignite")).toBeNull();
  });

  it("mounts the card wrapped in foil for common reveals too", () => {
    render(<CardReveal pending={commonReveal} />);
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    expect(screen.getByText("Sync masuk")).toBeInTheDocument();
  });

  it("tearing the pack swaps the sealed eyebrow for the rarity label", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByText("Sync masuk")).toBeInTheDocument();
    // Tap to tear the foil open — the card behind it is revealed.
    await u.click(screen.getByTestId("pack-wrapper"));
    expect(screen.getByText(/★ Luar Biasa/)).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Pembalik Keadaan" }),
    ).toBeInTheDocument();
  });

  it('"Lihat koleksi" marks seen and navigates to /kartu after the reveal', async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={commonReveal} />);
    // Tear the pack, then wait for the staggered action buttons to appear.
    await u.click(screen.getByTestId("pack-wrapper"));
    await u.click(await screen.findByText("Lihat koleksi"));

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/kartu/7/seen",
      expect.objectContaining({ method: "POST" }),
    );
    expect(visit).toHaveBeenCalledWith("/kartu", expect.anything());
  });

  it('"Tutup" marks seen and reloads the pendingReveal prop', async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    // "Tutup" is available even while the pack is still sealed.
    await u.click(screen.getByText("Tutup"));

    // The dialog disappears immediately — synchronously after the click,
    // without awaiting the seen-POST — proving the close isn't network-gated.
    expect(screen.queryByRole("dialog")).toBeNull();

    expect(fetchMock).toHaveBeenCalledWith(
      "/api/kartu/42/seen",
      expect.objectContaining({ method: "POST" }),
    );
    expect(reload).toHaveBeenCalledWith({ only: ["pendingReveal"] });
  });

  it("Escape key closes the reveal optimistically and marks seen", async () => {
    render(<CardReveal pending={epicReveal} />);
    await userEvent.setup().keyboard("{Escape}");
    // Closed instantly, not gated on the seen-POST resolving.
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/kartu/42/seen",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("clicking outside the card closes the reveal optimistically", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    // Outside-click only dismisses once the pack is torn (opened).
    await u.click(screen.getByTestId("pack-wrapper"));
    await u.click(screen.getByRole("dialog"));
    // Closed instantly, not gated on the seen-POST resolving.
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(fetchMock).toHaveBeenCalledWith(
      "/api/kartu/42/seen",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("does not unwrap the card via keyboard (touch-first tear)", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    // No keyboard gesture tears the foil — it stays sealed.
    await u.keyboard(" ");
    await u.keyboard("{Enter}");
    await u.keyboard("{ArrowRight}");
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    // The sealed eyebrow only flips to the rarity label once torn.
    expect(screen.getByText("Sync masuk")).toBeInTheDocument();
    expect(screen.queryByText(/★ Luar Biasa/)).toBeNull();
  });

  it("ignites the card with a rarity ring when the pack is torn", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    expect(screen.queryByTestId("card-ignite")).toBeNull();
    await u.click(screen.getByTestId("pack-wrapper")); // tear it open
    expect(screen.getByTestId("card-ignite")).toBeInTheDocument();
  });

  it("only POSTs /seen once even if the user double-clicks Tutup", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    const tutup = screen.getByText("Tutup");
    await u.click(tutup);
    // The optimistic close unmounts the reveal, so the second click lands on a
    // detached node; the sentRef guard also keeps it to a single POST.
    await u.click(tutup);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("renders the card with real km/duration/trimp values once revealed", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={commonReveal} />);
    await u.click(screen.getByTestId("pack-wrapper")); // tear the pack open
    // 5000m → 5.00 km, 1800s → 30 menit, trimp 42 shown as the TRIMP badge number
    expect(screen.getByText("5.00")).toBeInTheDocument();
    expect(screen.getByText("30 menit")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
  });

  it("shows Bagikan button after the card is revealed and opens the share modal", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={epicReveal} />);
    // Tear the pack open; the action buttons stagger in afterwards.
    await u.click(screen.getByTestId("pack-wrapper"));
    const bagikan = await screen.findByRole("button", { name: /Bagikan/ });
    expect(bagikan).toBeInTheDocument();
    await u.click(bagikan);
    // Share modal opens
    expect(screen.getByText(/Bagikan kartu/)).toBeInTheDocument();
    // Close the modal (covers () => setShareOpen(false))
    await u.click(screen.getByLabelText("Tutup"));
  });

  it("keeps the card wrapped until torn, then reveals the actions on tap", async () => {
    const u = userEvent.setup();
    render(<CardReveal pending={commonReveal} />);
    expect(screen.getByTestId("pack-wrapper")).toBeInTheDocument();
    // Lihat koleksi only exists once the card is revealed.
    expect(screen.queryByText("Lihat koleksi")).toBeNull();
    await u.click(screen.getByTestId("pack-wrapper"));
    expect(await screen.findByText("Lihat koleksi")).toBeInTheDocument();
  });

  it("skips the wrapper entirely under prefers-reduced-motion", async () => {
    vi.stubGlobal("matchMedia", () => ({ matches: true }));
    render(<CardReveal pending={commonReveal} />);
    // The card is already revealed — no foil to tear.
    expect(screen.queryByTestId("pack-wrapper")).toBeNull();
    // The reveal content (rarity eyebrow) is shown immediately.
    expect(screen.getByText(/★ Biasa/)).toBeInTheDocument();
    // The collection action is reachable without tearing.
    expect(await screen.findByText("Lihat koleksi")).toBeInTheDocument();
  });
});
