"use client";

import { useEffect, useRef } from "react";
import { DATA_CHANGED_EVENT as EVENT, DATA_CHANGED_KEY as STORAGE_KEY } from "./api";

/**
 * Pages stay up to date without a manual refresh. Each page hands its loader to useLiveRefresh,
 * which runs it again:
 *  - every few seconds while the tab is on screen (new orders, chats, join requests, alerts…),
 *  - the moment the tab comes back into view,
 *  - right after anything is saved, in this tab or another open NeoTalab tab (apiFetch calls
 *    announceChange after every successful write).
 * Hidden tabs don't poll. Loaders must not clear what is on screen while they reload, and must not
 * write anything themselves (that would announce a change and loop).
 */

export const LIVE_INTERVAL = 15000;

export function useLiveRefresh(refresh, { interval = LIVE_INTERVAL, enabled = true } = {}) {
  const latest = useRef(refresh);
  useEffect(() => { latest.current = refresh; });

  useEffect(() => {
    if (!enabled) return undefined;
    let running = false;
    let again = false;
    let soon = null;

    async function run() {
      if (document.hidden) return;
      if (running) { again = true; return; }
      running = true;
      try { await latest.current?.(); } catch { /* the next run tries again */ }
      running = false;
      if (again) { again = false; run(); }
    }
    // Several saves in a row (or a save plus the page's own reload) become one refresh.
    function runSoon() {
      clearTimeout(soon);
      soon = setTimeout(run, 400);
    }
    function onVisible() { if (!document.hidden) runSoon(); }
    function onStorage(e) { if (e.key === STORAGE_KEY) runSoon(); }

    const timer = setInterval(run, interval);
    window.addEventListener(EVENT, runSoon);
    window.addEventListener("storage", onStorage);
    window.addEventListener("focus", onVisible);
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      clearInterval(timer);
      clearTimeout(soon);
      window.removeEventListener(EVENT, runSoon);
      window.removeEventListener("storage", onStorage);
      window.removeEventListener("focus", onVisible);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [interval, enabled]);
}
