"use client";

import { useEffect, useRef, useState } from "react";
import { useLang } from "@/lib/i18n";
import { BEIRUT, MAPS_KEY, MAP_OPTIONS, formatPin, loadGoogleMaps, parsePin } from "@/lib/googleMaps";

/** "lat,lng" when the value already contains a pin, otherwise "". */
export function mapPin(value) {
  const point = parsePin(value);
  return point ? `${point.lat},${point.lng}` : "";
}

/**
 * A place on Google Maps, saved as "lat, lng" (the API also accepts a pasted Google Maps link).
 * Search the shop's name or address, tap the map, or drag the pin; "Use my location" works at the
 * shop. Without a Maps key Google only allows a view-only map, so the pin comes from "Use my
 * location" or a pasted link and the map shows where it landed.
 */
export default function LocationPicker({ value, onChange, t, inputId = "lp-link", place = "shop", compact = false }) {
  const { lang } = useLang();
  const box = useRef(null);
  const searchBox = useRef(null);
  const map = useRef(null);
  const pin = useRef(null);
  const onChangeRef = useRef(onChange);
  const [interactive, setInteractive] = useState(false);
  const [locating, setLocating] = useState(false);
  const [link, setLink] = useState(parsePin(value) ? "" : value || "");
  const point = parsePin(value);

  useEffect(() => { onChangeRef.current = onChange; });

  function setPin(maps, latlng, zoomIn) {
    if (!pin.current) {
      pin.current = new maps.Marker({ position: latlng, map: map.current, draggable: true, animation: maps.Animation.DROP });
      pin.current.addListener("dragend", (e) => onChangeRef.current(formatPin({ lat: e.latLng.lat(), lng: e.latLng.lng() })));
    } else {
      pin.current.setPosition(latlng);
    }
    if (zoomIn) { map.current.panTo(latlng); if (map.current.getZoom() < 17) map.current.setZoom(17); }
  }

  useEffect(() => {
    if (!MAPS_KEY) return undefined;
    let gone = false;
    loadGoogleMaps(lang).then(async (maps) => {
      if (gone || !box.current) return;
      map.current = new maps.Map(box.current, { ...MAP_OPTIONS, center: point || BEIRUT, zoom: point ? 17 : 9 });
      map.current.addListener("click", (e) => {
        const here = { lat: e.latLng.lat(), lng: e.latLng.lng() };
        setPin(maps, here, map.current.getZoom() < 15);
        setLink("");
        onChangeRef.current(formatPin(here));
      });
      if (point) setPin(maps, point, false);
      setInteractive(true);

      // Search box (Places): pick the shop or the street, the pin lands there.
      try {
        const { PlaceAutocompleteElement } = await maps.importLibrary("places");
        if (gone || !searchBox.current || searchBox.current.childElementCount) return;
        const search = new PlaceAutocompleteElement({ includedRegionCodes: ["lb"] });
        search.setAttribute("placeholder", t(place === "area" ? "db.loc.searchArea" : "db.loc.search"));
        const picked = async (where) => {
          await where.fetchFields({ fields: ["location"] });
          if (!where.location) return;
          const here = { lat: where.location.lat(), lng: where.location.lng() };
          setPin(maps, here, true);
          setLink("");
          onChangeRef.current(formatPin(here));
        };
        search.addEventListener("gmp-select", (e) => e.placePrediction && picked(e.placePrediction.toPlace()));
        search.addEventListener("gmp-placeselect", (e) => e.place && picked(e.place));
        searchBox.current.appendChild(search);
      } catch { /* Places not available: the map still works */ }
    }).catch(() => setInteractive(false));
    return () => { gone = true; pin.current?.setMap(null); pin.current = null; map.current = null; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- the map is built once; the pin follows `value` below.
  }, []);

  // A value set from outside (a pasted link, Discard) moves the pin.
  useEffect(() => {
    const maps = window.google?.maps;
    if (!interactive || !maps) return;
    if (point) {
      const p = pin.current?.getPosition();
      if (!p || Math.abs(p.lat() - point.lat) > 1e-6 || Math.abs(p.lng() - point.lng) > 1e-6) setPin(maps, point, true);
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
        if (interactive) setPin(window.google.maps, here, true);
        setLink("");
        onChange(formatPin(here));
      },
      () => setLocating(false),
      { enableHighAccuracy: true, timeout: 10000 },
    );
  }

  const embed = `https://maps.google.com/maps?q=${point ? `${point.lat},${point.lng}` : "Lebanon"}&z=${point ? 16 : 8}&hl=${lang}&output=embed`;
  const mapLabel = t(place === "area" ? "db.loc.mapArea" : "db.loc.map");
  const idle = t(interactive ? (place === "area" ? "db.loc.tapArea" : "db.loc.tap") : (place === "area" ? "db.loc.howArea" : "db.loc.how"));

  return (
    <div className={`lp${compact ? " compact" : ""}`}>
      {MAPS_KEY && <div className="lp-search" ref={searchBox} />}
      {MAPS_KEY
        ? <div className="lp-map" ref={box} role="application" aria-label={mapLabel} />
        : <iframe className="lp-map" title={mapLabel} src={embed} loading="lazy" referrerPolicy="no-referrer-when-downgrade" />}
      <div className="lp-bar">
        <span className="lp-status">{point ? `📍 ${t(interactive ? "db.loc.set" : "db.loc.setStatic")}` : idle}</span>
        <div className="lp-actions">
          <button type="button" className="btn-sm" onClick={locate} disabled={locating}>{locating ? "…" : `◎ ${t("db.loc.here")}`}</button>
          {!interactive && <a className="btn-sm" href="https://www.google.com/maps" target="_blank" rel="noopener noreferrer">{t("db.loc.open")}</a>}
        </div>
      </div>
      <details className="lp-paste" open={!MAPS_KEY || undefined}>
        <summary>{t("db.loc.paste")}</summary>
        <input id={inputId} dir="ltr" value={link} placeholder="https://maps.app.goo.gl/…" aria-label={t("db.loc.paste")}
          onChange={(e) => { setLink(e.target.value); onChange(e.target.value); }} />
      </details>
    </div>
  );
}
