import { beforeEach, describe, expect, it, vi } from "vitest";
import { writeUrlParam } from "@/lib/urlParam";

// A bare stand-in for the browser's address bar and history.
let href;
beforeEach(() => {
  href = "https://neotalab.com/backoffice/shop/settings?tab=alerts";
  vi.stubGlobal("window", {
    location: { get href() { return href; } },
    history: { state: null, replaceState: (_s, _t, url) => { href = String(url); } },
  });
});

describe("remembering a pick in the address bar", () => {
  it("replaces the tab so a refresh reopens it", () => {
    writeUrlParam("tab", "payments", "shop");
    expect(href).toBe("https://neotalab.com/backoffice/shop/settings?tab=payments");
  });

  it("drops the parameter when the pick is the default", () => {
    writeUrlParam("tab", "shop", "shop");
    expect(href).toBe("https://neotalab.com/backoffice/shop/settings");
  });

  it("keeps the other parameters", () => {
    href = "https://neotalab.com/owner?m=7&tab=bot";
    writeUrlParam("tab", "billing", "info");
    expect(href).toBe("https://neotalab.com/owner?m=7&tab=billing");
    writeUrlParam("m", null);
    expect(href).toBe("https://neotalab.com/owner?tab=billing");
  });
});
