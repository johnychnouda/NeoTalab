"use client";

import { useEffect, useRef, useState } from "react";
import { useLang } from "@/lib/i18n";

const BEIRUT = { lat: 33.8938, lng: 35.5018 };
const KEY = process.env.NEXT_PUBLIC_GOOGLE_MAPS_KEY || "";
const coords = (v) => {
  const m = String(v || "").match(/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/);
  return m ? { lat: Number(m[1]), lng: Number(m[2]) } : null;
};
const fmt = ({ lat, lng }) => `${lat.toFixed(6)}, ${lng.toFixed(6)}`;

function loadGoogleMaps() {
  if (window.google?.maps?.Map) return Promise.resolve(window.google.maps);
  window.__ntGoogleMaps ??= new Promise((resolve, reject) => {
    const s = document.createElement("script");
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(KEY)}&v=weekly`;
    s.async = true;
    s.onload = () => resolve(window.google.maps);
    s.onerror = reject;
    document.head.appendChild(s);
  });
  return window.__ntGoogleMaps;
}

/**
 * The shop's location on Google Maps, saved as "lat, lng" (the API also accepts a pasted Google
 * Maps link). With NEXT_PUBLIC_GOOGLE_MAPS_KEY set the map is interactive: tap to drop the pin,
 * drag it to adjust. Without a key Google only allows a view-only map, so the pin is set with
 * "Use my location" or a pasted link, and the map shows where it landed.
 */
export default function LocationPicker({ value, onChange, t }) {
  const { lang } = useLang();
  const box = useRef(null);
  const map = useRef(null);
  const pin = useRef(null);
  const onChangeRef = useRef(onChange);
  const [interactive, setInteractive] = useState(false);
  const [locating, setLocating] = useState(false);
  const [link, setLink] = useState(coords(value) ? "" : value || "");
  const point = coords(value);

  useEffect(() => { onChangeRef.current = onChange; });

  function place(maps, latlng, zoomIn) {
    if (!pin.current) {
      pin.current = new maps.Marker({ position: latlng, map: map.current, draggable: true });
      pin.current.addListener("dragend", (e) => onChangeRef.current(fmt({ lat: e.latLng.lat(), lng: e.latLng.lng() })));
    } else {
      pin.current.setPosition(latlng);
    }
    if (zoomIn) { map.current.panTo(latlng); if (map.current.getZoom() < 16) map.current.setZoom(16); }
  }

  useEffect(() => {
    if (!KEY) return undefined;
    let gone = false;
    loadGoogleMaps().then((maps) => {
      if (gone || !box.current) return;
      map.current = new maps.Map(box.current, {
        center: point || BEIRUT, zoom: point ? 16 : 9,
        mapTypeControl: false, streetViewControl: false, fullscreenControl: false, clickableIcons: false,
        gestureHandling: "cooperative",
      });
      map.current.addListener("click", (e) => {
        const here = { lat: e.latLng.lat(), lng: e.latLng.lng() };
        place(maps, here, map.current.getZoom() < 14);
        onChangeRef.current(fmt(here));
      });
      if (point) place(maps, point, false);
      setInteractive(true);
    }).catch(() => setInteractive(false));
    return () => { gone = true; pin.current?.setMap(null); pin.current = null; map.current = null; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- the map is built once; the pin follows `value` below.
  }, []);

  // A value set from outside (a pasted link resolved on save, Discard) moves the pin.
  useEffect(() => {
    const maps = window.google?.maps;
    if (!interactive || !maps) return;
    if (point) {
      const p = pin.current?.getPosition();
      if (!p || Math.abs(p.lat() - point.lat) > 1e-6 || Math.abs(p.lng() - point.lng) > 1e-6) place(maps, point, true);
    } else if (pin.current) {
      pin.current.setMap(null);
      pin.current = null;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, interactive]);

  function locate() {
    if (!navigator.geolocation) return;
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLocating(false);
        const here = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        if (interactive) place(window.google.maps, here, true);
        setLink("");
        onChange(fmt(here));
      },
      () => setLocating(false),
      { enableHighAccuracy: true, timeout: 10000 },
    );
  }

  const embed = `https://maps.google.com/maps?q=${point ? `${point.lat},${point.lng}` : "Lebanon"}&z=${point ? 16 : 8}&hl=${lang}&output=embed`;

  return (
    <div className="lp">
      {interactive || KEY
        ? <div className="lp-map" ref={box} role="application" aria-label={t("db.loc.map")} />
        : <iframe className="lp-map" title={t("db.loc.map")} src={embed} loading="lazy" referrerPolicy="no-referrer-when-downgrade" />}
      <div className="lp-bar">
        <span className="lp-status">{point ? `📍 ${t(interactive ? "db.loc.set" : "db.loc.setStatic")}` : t(interactive ? "db.loc.tap" : "db.loc.how")}</span>
        <div className="lp-actions">
          <button type="button" className="btn-sm" onClick={locate} disabled={locating}>{locating ? "…" : `◎ ${t("db.loc.here")}`}</button>
          {!interactive && <a className="btn-sm" href="https://www.google.com/maps" target="_blank" rel="noopener noreferrer">{t("db.loc.open")}</a>}
        </div>
      </div>
      <div className="lp-paste">
        <label htmlFor="lp-link">{t("db.loc.paste")}</label>
        <input id="lp-link" dir="ltr" value={link} placeholder="https://maps.app.goo.gl/…"
          onChange={(e) => { setLink(e.target.value); onChange(e.target.value); }} />
      </div>
    </div>
  );
}
