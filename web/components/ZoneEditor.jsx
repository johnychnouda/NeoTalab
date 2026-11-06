"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useLang } from "@/lib/i18n";
import { formatMoney } from "@/lib/money";
import { BEIRUT, MAPS_KEY, MAP_OPTIONS, loadGoogleMaps, parsePin, shopMarkerIcon } from "@/lib/googleMaps";
import { COLORS, DEFAULT_ROWS, MAX_ROWS, planSave, rowsFromZones, rowsProblem } from "@/lib/zoneDraft";

/**
 * Delivery areas as distance rows: "up to 3 km: 1$, up to 6 km: 2$". Each row is a circle around the shop pin,
 * drawn on the map as a preview. A customer's WhatsApp location pin is matched to the smallest circle it falls
 * in. Areas drawn on the map in the past stay (their prices can change, or they can be removed). One Save for all.
 */
export default function ZoneEditor({ api, toast, t, currency, shopPin, zones, onChanged, onSetShopLocation }) {
  const { lang } = useLang();
  const box = useRef(null);
  const maps = useRef(null);
  const map = useRef(null);
  const overlays = useRef([]);
  const [ready, setReady] = useState(false);
  const [rings, setRings] = useState([]);
  const [custom, setCustom] = useState([]);
  const [base, setBase] = useState("");
  const [bad, setBad] = useState(null); // index of the row that stopped the save
  const [busy, setBusy] = useState(false);

  const shop = useMemo(() => parsePin(shopPin), [shopPin]);
  const money = (v) => formatMoney(v, currency);
  const ringName = (km) => t("db.zone.ringName", { km });

  // Rows follow the saved zones (a brand new shop starts on 3, 6 and 10 km, not saved until it taps Save).
  useEffect(() => {
    const saved = rowsFromZones(zones, shop);
    const start = zones.length === 0 ? { rings: DEFAULT_ROWS.map((r) => ({ ...r })), custom: [] } : saved;
    setRings(start.rings);
    setCustom(start.custom);
    setBase(zones.length === 0 ? "" : JSON.stringify(start));
    setBad(null);
  }, [zones, shop]);

  const dirty = JSON.stringify({ rings, custom }) !== base;
  const all = [...rings, ...custom];
  const setRing = (i, patch) => { setBad(null); setRings((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r))); };
  const setCustomRow = (i, patch) => { setBad(null); setCustom((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r))); };

  useEffect(() => {
    if (!MAPS_KEY) return undefined;
    let gone = false;
    loadGoogleMaps(lang).then((g) => {
      if (gone || !box.current) return;
      maps.current = g;
      map.current = new g.Map(box.current, { ...MAP_OPTIONS, center: shop || BEIRUT, zoom: shop ? 12 : 9 });
      setReady(true);
    }).catch(() => {});
    return () => { gone = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- built once
  }, []);

  // The preview: a circle per row around the shop (furthest first so the near ones stay on top), plus the old drawn areas.
  const preview = JSON.stringify({ rings: rings.map((r) => [r.km, r.fee]), shop, zones: zones.map((z) => z.id) });
  useEffect(() => {
    const g = maps.current;
    if (!ready || !g) return;
    overlays.current.forEach((o) => o.setMap(null));
    overlays.current = [];
    const bounds = new g.LatLngBounds();
    const label = (position, fee) => overlays.current.push(new g.Marker({
      position, map: map.current, clickable: false, zIndex: 500, icon: { path: g.SymbolPath.CIRCLE, scale: 0 },
      label: { text: money(parseFloat(fee) || 0), color: "#ffffff", fontSize: "12px", fontWeight: "700", className: "zn-label" },
    }));
    const style = (color) => ({ map: map.current, strokeColor: color, strokeOpacity: 0.9, strokeWeight: 2, fillColor: color, fillOpacity: 0.12, clickable: false });

    if (shop) {
      overlays.current.push(new g.Marker({ position: shop, map: map.current, icon: shopMarkerIcon(g), title: t("db.zone.shop"), zIndex: 999 }));
      bounds.extend(shop);
      [...rings].filter((r) => Number(r.km) >= 0.5 && Number(r.km) <= 60).sort((a, b) => Number(b.km) - Number(a.km)).forEach((r, i, list) => {
        const color = COLORS[(list.length - 1 - i) % COLORS.length];
        const circle = new g.Circle({ ...style(color), center: shop, radius: Number(r.km) * 1000 });
        overlays.current.push(circle);
        bounds.union(circle.getBounds());
        label(g.geometry.spherical.computeOffset(shop, Number(r.km) * 1000, 0), r.fee);
      });
    }
    custom.forEach((row) => {
      const z = zones.find((x) => x.id === row.id);
      if (!z?.area) return;
      const color = z.color || COLORS[1];
      const shape = z.area.type === "circle"
        ? new g.Circle({ ...style(color), center: { lat: z.area.center[0], lng: z.area.center[1] }, radius: z.area.radius })
        : new g.Polygon({ ...style(color), paths: z.area.points.map(([lat, lng]) => ({ lat, lng })) });
      overlays.current.push(shape);
      if (shape.getBounds) bounds.union(shape.getBounds()); else z.area.points.forEach(([lat, lng]) => bounds.extend({ lat, lng }));
    });
    if (!bounds.isEmpty()) map.current.fitBounds(bounds, 40);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- redraw when the numbers change
  }, [ready, preview]);

  async function save() {
    const problem = rowsProblem(all);
    if (problem) {
      setBad(problem.index);
      const message = { km: "db.zone.kmError", dupe: "db.zone.dupeError", fee: "db.zone.feeRequired", min: "db.zone.minError" }[problem.kind];
      toast(t(message), "error");
      return;
    }
    if (!shop && rings.length > 0) return; // rows are circles around the shop pin
    setBusy(true);
    try {
      const plan = planSave({ rings, custom, zones, shop: shop || BEIRUT, ringName });
      for (const u of plan.update) await api("PATCH", `/settings/zones/${u.id}`, u.body);
      for (const body of plan.create) await api("POST", "/settings/zones", body);
      for (const id of plan.remove) await api("DELETE", `/settings/zones/${id}`);
      toast(t("db.saved"));
      onChanged();
    } catch (e) { toast(e.message, "error"); }
    setBusy(false);
  }

  return (
    <div className="zn">
      {!shop && (
        <div className="sset-warn" role="status">
          <span>{t("db.zone.noShopPin")}</span>
          <button type="button" className="btn-sm" onClick={onSetShopLocation}>{t("db.zone.setShop")}</button>
        </div>
      )}

      <div className="zn-layout">
        <aside className="zn-side">
          <div className="zn-quick">
            <p className="sset-hint">{t("db.zone.quickDesc")}</p>
            <div className="zn-rings">
              <span>{t("db.zone.upTo")}</span><span>{t("db.zone.fee")}</span><span>{t("db.zone.min")}</span><span />
              {rings.map((r, i) => (
                <RingRow key={r.id || `new-${i}`} r={r} invalid={bad === i} color={COLORS[i % COLORS.length]}
                  onChange={(patch) => setRing(i, patch)} onRemove={() => { setBad(null); setRings(rings.filter((_, j) => j !== i)); }} />
              ))}
              {custom.map((r, i) => (
                <RingRow key={r.id} r={r} invalid={bad === rings.length + i} color={COLORS[1]} title={r.name}
                  onChange={(patch) => setCustomRow(i, patch)} onRemove={() => { setBad(null); setCustom(custom.filter((_, j) => j !== i)); }} />
              ))}
            </div>
            {custom.length > 0 && <p className="sset-hint">{t("db.zone.customHint")}</p>}
            {rings.length < MAX_ROWS && (
              <button type="button" className="btn-sm" onClick={() => {
                const last = Math.max(0, ...rings.map((r) => Number(r.km) || 0));
                setRings([...rings, { km: String(last + (last >= 10 ? 5 : 3)), fee: "", min: "" }]);
              }}>+ {t("db.zone.addRing")}</button>
            )}
            <button type="button" className="btn-primary zn-wide" onClick={save} disabled={busy || !dirty || (!shop && rings.length > 0)}>
              {busy ? "…" : t("db.zone.saveRows")}
            </button>
          </div>
          <p className="sset-hint">{t("db.zone.howItWorks")}</p>
        </aside>

        <div className="zn-map-wrap">
          {MAPS_KEY
            ? <div className="zn-map" ref={box} role="application" aria-label={t("db.zone.title")} />
            : <div className="zn-map zn-nomap"><p>{t("db.zone.noKey")}</p></div>}
        </div>
      </div>
    </div>
  );
}

function RingRow({ r, onChange, onRemove, color, invalid, title }) {
  return (
    <>
      <span className="zn-ring-km">
        <i style={{ background: color }} />
        {title
          ? <span className="zn-ring-name" title={title}>{title}</span>
          : <input type="number" min="0.5" max="60" step="0.5" value={r.km} onChange={(e) => onChange({ km: e.target.value })} aria-label="km" aria-invalid={invalid || undefined} />}
      </span>
      <input type="number" min="0" step="0.5" value={r.fee} onChange={(e) => onChange({ fee: e.target.value })} placeholder="0" aria-label="fee" aria-invalid={invalid || undefined} />
      <input type="number" min="0" step="0.5" value={r.min} onChange={(e) => onChange({ min: e.target.value })} placeholder="0" aria-label="minimum" />
      <button type="button" className="zn-x" onClick={onRemove} aria-label="remove">✕</button>
    </>
  );
}
