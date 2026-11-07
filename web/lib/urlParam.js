"use client";
import { useCallback, useEffect, useState } from "react";

/** Writes one query parameter into the address bar without adding history, so a refresh keeps the pick. */
export function writeUrlParam(key, value, fallback = "") {
  try {
    const u = new URL(window.location.href);
    if (value === fallback || value == null) u.searchParams.delete(key); else u.searchParams.set(key, value);
    window.history.replaceState(window.history.state, "", u);
  } catch { /* the pick still works, it just is not remembered */ }
}

/** useState that also lives in `?key=` of the address bar: read once after load, written on every change. */
export function useUrlParam(key, fallback = "") {
  const [value, setValue] = useState(fallback);
  useEffect(() => {
    const v = new URLSearchParams(window.location.search).get(key);
    if (v) setValue(v);
  }, [key]);
  const set = useCallback((next) => { setValue(next); writeUrlParam(key, next, fallback); }, [key, fallback]);
  return [value, set];
}
