// Shared helpers: console/network watching and the horizontal-overflow probe.
export function watchPage(page) {
  const problems = [];
  page.on("console", (m) => {
    if (m.type() === "error") problems.push(`console: ${m.text().slice(0, 200)}`);
  });
  page.on("pageerror", (e) => problems.push(`pageerror: ${e.message.slice(0, 200)}`));
  page.on("response", (r) => {
    if (r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url().slice(0, 160)}`);
  });
  return problems;
}

// True when the page is wider than the screen (a sideways scroll), ignoring scroll areas built for it.
export async function horizontalOverflow(page) {
  return page.evaluate(() => {
    const cw = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth <= cw + 1) return null;
    const culprits = [...document.querySelectorAll("body *")]
      .filter((e) => {
        const r = e.getBoundingClientRect();
        if (r.width === 0 || getComputedStyle(e).position === "fixed") return false;
        if (e.closest(".table-scroll,.filter-pills,.wa-strip,.wa-tabs,.inbox-filters,.sset-tabs,.dash-body,.menu-nav,.site-links")) return false;
        return r.right > cw + 1;
      })
      .slice(0, 3)
      .map((e) => `${e.tagName}.${String(e.className).slice(0, 50)}`);
    return { scrollWidth: document.documentElement.scrollWidth, clientWidth: cw, culprits };
  });
}
