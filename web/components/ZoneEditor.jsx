"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useLang } from "@/lib/i18n";
import { formatMoney } from "@/lib/money";
import { BEIRUT, MAPS_KEY, MAP_OPTIONS, loadGoogleMaps, parsePin, shopMarkerIcon } from "@/lib/googleMaps";
import { ConfirmModal } from "@/components/ui";

const COLORS = ["#25d366", "#3b82f6", "#f59e0b", "#a855f7", "#ef4444", "#14b8a6", "#eab308", "#ec4899"];
const RINGS = [{ km: 3, fee: "1", min: "" }, { km: 6, fee: "2", min: "" }, { km: 10, fee: "3", min: "" }];
const minutesFor = (metres) => Math.round(5 + (metres / 1000) * 3);
const sizeOf = (z) => (z.area?.type === "circle" ? Math.PI * z.area.radius ** 2 : z.area ? polygonSize(z.area.points) : Infinity);

function polygonSize(points) {
  let s = 0;
  for (let i = 0; i < points.length; i++) {
    const [y1, x1] = points[i];
    const [y2, x2] = points[(i + 1) % points.length];
    s += x1 * y2 - x2 * y1;
  }
  return Math.abs(s) * 6e9; // only used to order zones, smallest first
}

/**
 * Delivery zones on Google Maps, the way delivery apps do it: rings around the shop (quick setup),
 * circles or shapes drawn on the map, each with its fee, minimum order and delivery time. A customer's
 * WhatsApp location pin is matched to the zone it falls in (smallest first when zones overlap).
 * Zones save straight away; they are not part of the Settings save bar.
 */
export default function ZoneEditor({ api, toast, t, currency, shopPin, zones, onChanged, onSetShopLocation }) {
  const { lang } = useLang();
  const box = useRef(null);
  const maps = useRef(null);
  const map = useRef(null);
  const overlays = useRef([]);
  const editShape = useRef(null);
  const drawLayer = useRef([]);
  const [ready, setReady] = useState(false);
  const [draft, setDraft] = useState(null); // { id?, name, fee, min, mins, color, area }
  const [drawing, setDrawing] = useState(null); // list of [lat, lng] while drawing a shape
  const [drawFor, setDrawFor] = useState(null); // the zone a new shape is drawn for (one that isn't on the map yet)
  const [rings, setRings] = useState(RINGS);
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(null);

  const shop = parsePin(shopPin);
  const sorted = useMemo(() => [...zones].sort((a, b) => sizeOf(a) - sizeOf(b)), [zones]);
  const money = (v) => formatMoney(v, currency);
  const nextColor = () => COLORS[zones.length % COLORS.length];

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

  // All saved zones (except the one being edited) and the shop pin.
  useEffect(() => {
    const g = maps.current;
    if (!ready || !g) return;
    overlays.current.forEach((o) => o.setMap(null));
    overlays.current = [];
    const bounds = new g.LatLngBounds();

    if (shop) {
      overlays.current.push(new g.Marker({ position: shop, map: map.current, icon: shopMarkerIcon(g), title: t("db.zone.shop"), zIndex: 999 }));
      bounds.extend(shop);
    }
    [...sorted].reverse().forEach((z) => {
      if (!z.area || z.id === draft?.id) return;
      const color = z.color || "#25d366";
      const style = { map: map.current, strokeColor: color, strokeOpacity: 0.9, strokeWeight: 2, fillColor: color, fillOpacity: draft ? 0.06 : 0.14, clickable: !draft && !drawing };
      const shape = z.area.type === "circle"
        ? new g.Circle({ ...style, center: { lat: z.area.center[0], lng: z.area.center[1] }, radius: z.area.radius })
        : new g.Polygon({ ...style, paths: z.area.points.map(([lat, lng]) => ({ lat, lng })) });
      shape.addListener("click", () => startEdit(z));
      overlays.current.push(shape);
      const b = shape.getBounds ? shape.getBounds() : null;
      if (b) bounds.union(b); else z.area.points.forEach(([lat, lng]) => bounds.extend({ lat, lng }));

      // Fee label on the zone's edge (circles) or middle (shapes), like a delivery app's zone map.
      const at = z.area.type === "circle"
        ? g.geometry.spherical.computeOffset({ lat: z.area.center[0], lng: z.area.center[1] }, z.area.radius, 0)
        : shape.getPath().getArray().reduce((acc, p, _, arr) => ({ lat: acc.lat + p.lat() / arr.length, lng: acc.lng + p.lng() / arr.length }), { lat: 0, lng: 0 });
      overlays.current.push(new g.Marker({
        position: at, map: map.current, clickable: false, zIndex: 500,
        icon: { path: g.SymbolPath.CIRCLE, scale: 0 },
        label: { text: money(z.delivery_fee), color: "#ffffff", fontSize: "12px", fontWeight: "700", className: "zn-label" },
      }));
    });
    if (!draft && !drawing) {
      if (sorted.some((z) => z.area)) map.current.fitBounds(bounds, 40);
      else if (shop) { map.current.setCenter(shop); map.current.setZoom(13); }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ready, sorted, draft?.id, Boolean(draft), Boolean(drawing), shopPin]);

  // The zone being edited: an editable circle or shape that updates the draft.
  useEffect(() => {
    const g = maps.current;
    editShape.current?.setMap(null);
    editShape.current = null;
    if (!ready || !g || !draft?.area) return;
    const color = draft.color;
    const style = { map: map.current, strokeColor: color, strokeWeight: 3, fillColor: color, fillOpacity: 0.25, editable: true, zIndex: 900 };
    if (draft.area.type === "circle") {
      const c = new g.Circle({ ...style, draggable: true, center: { lat: draft.area.center[0], lng: draft.area.center[1] }, radius: draft.area.radius });
      const sync = () => setDraft((d) => d && ({ ...d, area: { type: "circle", center: [c.getCenter().lat(), c.getCenter().lng()], radius: Math.round(c.getRadius()) } }));
      c.addListener("radius_changed", sync);
      c.addListener("dragend", sync);
      editShape.current = c;
      map.current.fitBounds(c.getBounds(), 40);
    } else {
      const p = new g.Polygon({ ...style, draggable: true, paths: draft.area.points.map(([lat, lng]) => ({ lat, lng })) });
      const sync = () => setDraft((d) => d && ({ ...d, area: { type: "polygon", points: p.getPath().getArray().map((x) => [x.lat(), x.lng()]) } }));
      ["set_at", "insert_at", "remove_at"].forEach((ev) => p.getPath().addListener(ev, sync));
      p.addListener("dragend", sync);
      editShape.current = p;
      const b = new g.LatLngBounds();
      p.getPath().forEach((x) => b.extend(x));
      map.current.fitBounds(b, 40);
    }
    // Only rebuild when another zone is opened or the shape type changes, not on every drag.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ready, draft?.key, draft?.color]);

  // Drawing a new shape: each tap on the map adds a corner (one listener for the whole drawing).
  const isDrawing = drawing !== null;
  useEffect(() => {
    if (!ready || !isDrawing || !map.current) return undefined;
    map.current.setOptions({ draggableCursor: "crosshair", disableDoubleClickZoom: true });
    const l = map.current.addListener("click", (e) => setDrawing((pts) => (pts ? [...pts, [e.latLng.lat(), e.latLng.lng()]] : pts)));
    return () => { l.remove(); map.current?.setOptions({ draggableCursor: null, disableDoubleClickZoom: false }); };
  }, [ready, isDrawing]);

  useEffect(() => {
    const g = maps.current;
    drawLayer.current.forEach((o) => o.setMap(null));
    drawLayer.current = [];
    if (!ready || !g || !drawing?.length) return;
    const color = nextColor();
    const path = drawing.map(([lat, lng]) => ({ lat, lng }));
    drawLayer.current.push(drawing.length >= 3
      ? new g.Polygon({ map: map.current, paths: path, strokeColor: color, strokeWeight: 3, fillColor: color, fillOpacity: 0.15, clickable: false })
      : new g.Polyline({ map: map.current, path, strokeColor: color, strokeWeight: 3, clickable: false }));
    path.forEach((position) => drawLayer.current.push(new g.Marker({
      map: map.current, position, clickable: false,
      icon: { path: g.SymbolPath.CIRCLE, scale: 5, fillColor: "#fff", fillOpacity: 1, strokeColor: color, strokeWeight: 2 },
    })));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ready, drawing]);

  function startEdit(z) {
    setDrawing(null);
    setDraft({
      key: `${z.id}-${Date.now()}`, id: z.id, name: z.name, fee: String(Number(z.delivery_fee)), min: Number(z.minimum_order) ? String(Number(z.minimum_order)) : "",
      mins: String(z.delivery_mins ?? 15), color: z.color || COLORS[0], area: z.area || null,
    });
  }

  function startCircle() {
    const center = shop || (map.current ? { lat: map.current.getCenter().lat(), lng: map.current.getCenter().lng() } : BEIRUT);
    const radius = 2000;
    setDrawing(null);
    setDraft({ key: `new-${Date.now()}`, name: "", fee: "", min: "", mins: String(minutesFor(radius)), color: nextColor(), area: { type: "circle", center: [center.lat, center.lng], radius } });
  }

  function startDrawing(forDraft = null) {
    setDraft(null);
    setDrawFor(forDraft);
    setDrawing([]);
  }

  function finishDrawing() {
    if (!drawing || drawing.length < 3) return;
    const base = drawFor || { name: "", fee: "", min: "", mins: "20", color: nextColor() };
    setDraft({ ...base, key: `shape-${Date.now()}`, area: { type: "polygon", points: drawing } });
    setDrawing(null);
    setDrawFor(null);
  }

  function circleFor(d) {
    const center = shop || BEIRUT;
    setDraft({ ...d, key: `circle-${Date.now()}`, area: { type: "circle", center: [center.lat, center.lng], radius: 2000 } });
  }

  function cancel() {
    setDraft(null);
    setDrawing(null);
    setDrawFor(null);
  }

  async function save() {
    if (!draft.name.trim()) { toast(t("db.zone.nameRequired"), "error"); return; }
    if (MAPS_KEY && !draft.area) { toast(t("db.zone.drawFirst"), "error"); return; }
    setBusy(true);
    const body = {
      name: draft.name.trim(), color: draft.color, area: draft.area,
      deliveryFee: parseFloat(draft.fee) || 0, minimumOrder: parseFloat(draft.min) || 0, deliveryMins: parseInt(draft.mins, 10) || 15,
    };
    try {
      if (draft.id) await api("PATCH", `/settings/zones/${draft.id}`, body);
      else await api("POST", "/settings/zones", body);
      toast(t("db.saved"));
      setDraft(null);
      onChanged();
    } catch (e) { toast(e.message, "error"); }
    setBusy(false);
  }

  async function createRings() {
    if (!shop) return;
    setBusy(true);
    try {
      const list = rings.filter((r) => Number(r.km) > 0).sort((a, b) => a.km - b.km);
      for (const [i, r] of list.entries()) {
        await api("POST", "/settings/zones", {
          name: t("db.zone.ringName", { km: r.km }), color: COLORS[i % COLORS.length],
          area: { type: "circle", center: [shop.lat, shop.lng], radius: Math.round(Number(r.km) * 1000) },
          deliveryFee: parseFloat(r.fee) || 0, minimumOrder: parseFloat(r.min) || 0, deliveryMins: minutesFor(Number(r.km) * 1000),
        });
      }
      toast(t("db.saved"));
      onChanged();
    } catch (e) { toast(e.message, "error"); }
    setBusy(false);
  }

  const km = draft?.area?.type === "circle" ? (draft.area.radius / 1000).toFixed(1) : null;
  const setD = (k, v) => setDraft((d) => ({ ...d, [k]: v }));

  return (
    <div className="zn">
      {!shop && (
        <div className="sset-warn" role="status">
          <span>{t("db.zone.noShopPin")}</span>
          <button type="button" className="btn-sm" onClick={onSetShopLocation}>{t("db.zone.setShop")}</button>
        </div>
      )}

      <div className="zn-layout">
        <div className="zn-map-wrap">
          {MAPS_KEY
            ? <div className="zn-map" ref={box} role="application" aria-label={t("db.zone.title")} />
            : <div className="zn-map zn-nomap"><p>{t("db.zone.noKey")}</p></div>}
          {drawing && (
            <div className="zn-drawbar" role="status">
              <span>{drawing.length < 3 ? t("db.zone.drawHint") : t("db.zone.drawMore", { n: drawing.length })}</span>
              <div>
                <button type="button" className="btn-sm" onClick={() => setDrawing((p) => p.slice(0, -1))} disabled={!drawing.length}>{t("db.zone.undo")}</button>
                <button type="button" className="btn-sm" onClick={cancel}>{t("db.cancel")}</button>
                <button type="button" className="btn-primary" onClick={finishDrawing} disabled={drawing.length < 3}>{t("db.zone.finish")}</button>
              </div>
            </div>
          )}
        </div>

        <aside className="zn-side">
          {draft ? (
            <div className="zn-form">
              <h3><span className="zn-dot" style={{ background: draft.color }} />{draft.id ? t("db.zone.edit") : t("db.zone.add")}</h3>
              {draft.area && <p className="sset-hint">{t("db.zone.editHint")}{km ? ` · ${t("db.zone.km", { km })}` : ""}</p>}
              {!draft.area && MAPS_KEY && (
                <div className="zn-choose">
                  <p className="sset-hint">{t("db.zone.notOnMap")}</p>
                  <button type="button" className="btn-sm" onClick={() => circleFor(draft)}>◯ {t("db.zone.addCircle")}</button>
                  <button type="button" className="btn-sm" onClick={() => startDrawing(draft)}>✏️ {t("db.zone.addShape")}</button>
                </div>
              )}
              <label className="zn-field"><span>{t("db.zone.name")}</span>
                <input value={draft.name} onChange={(e) => setD("name", e.target.value)} placeholder={km ? t("db.zone.ringName", { km: Math.round(km) }) : "Hamra · الحمرا"} autoFocus /></label>
              <div className="zn-row">
                <label className="zn-field"><span>{t("db.zone.fee")}</span>
                  <input type="number" min="0" step="0.5" value={draft.fee} onChange={(e) => setD("fee", e.target.value)} placeholder="1.50" /></label>
                <label className="zn-field"><span>{t("db.zone.min")}</span>
                  <input type="number" min="0" step="0.5" value={draft.min} onChange={(e) => setD("min", e.target.value)} placeholder="0" /></label>
              </div>
              <label className="zn-field"><span>{t("db.zone.time")}</span>
                <input type="number" min="1" max="240" value={draft.mins} onChange={(e) => setD("mins", e.target.value)} /></label>
              <div className="zn-colors" role="radiogroup" aria-label={t("db.zone.color")}>
                {COLORS.map((c) => (
                  <button key={c} type="button" role="radio" aria-checked={draft.color === c} aria-label={c} style={{ background: c }}
                    className={draft.color === c ? "on" : ""} onClick={() => setD("color", c)} />
                ))}
              </div>
              <div className="zn-actions">
                {draft.id && <button type="button" className="btn-sm btn-sm-red" onClick={() => setConfirm(draft)}>{t("db.zone.delete")}</button>}
                <span />
                <button type="button" className="btn-sm" onClick={cancel} disabled={busy}>{t("db.cancel")}</button>
                <button type="button" className="btn-primary" onClick={save} disabled={busy}>{busy ? "…" : t("db.save")}</button>
              </div>
            </div>
          ) : zones.length === 0 ? (
            <div className="zn-quick">
              <h3>{t("db.zone.quick")}</h3>
              <p className="sset-hint">{t("db.zone.quickDesc")}</p>
              <div className="zn-rings">
                <span>{t("db.zone.upTo")}</span><span>{t("db.zone.fee")}</span><span>{t("db.zone.min")}</span><span />
                {rings.map((r, i) => (
                  <RingRow key={i} r={r} onChange={(patch) => setRings(rings.map((x, j) => (j === i ? { ...x, ...patch } : x)))}
                    onRemove={rings.length > 1 ? () => setRings(rings.filter((_, j) => j !== i)) : null} color={COLORS[i % COLORS.length]} />
                ))}
              </div>
              {rings.length < 6 && (
                <button type="button" className="btn-sm" onClick={() => setRings([...rings, { km: (Number(rings.at(-1)?.km) || 0) + 4, fee: "", min: "" }])}>+ {t("db.zone.addRing")}</button>
              )}
              <button type="button" className="btn-primary zn-wide" onClick={createRings} disabled={busy || !shop}>
                {busy ? "…" : t("db.zone.create", { n: rings.length })}
              </button>
              <div className="zn-or"><span>{t("db.zone.or")}</span></div>
              <div className="zn-add">
                <button type="button" className="btn-sm" onClick={startCircle} disabled={!ready}>◯ {t("db.zone.addCircle")}</button>
                <button type="button" className="btn-sm" onClick={() => startDrawing()} disabled={!ready}>✏️ {t("db.zone.addShape")}</button>
              </div>
            </div>
          ) : (
            <div className="zn-list">
              <ul>
                {sorted.map((z) => (
                  <li key={z.id}>
                    <button type="button" onClick={() => startEdit(z)}>
                      <span className="zn-dot" style={{ background: z.color || "#25d366" }} />
                      <span className="zn-main">
                        <strong>{z.name}</strong>
                        <small>
                          {money(z.delivery_fee)}
                          {Number(z.minimum_order) > 0 ? ` · ${t("db.zone.minShort")} ${money(z.minimum_order)}` : ""}
                          {` · ${z.delivery_mins ?? 15} min`}
                        </small>
                        {!z.area && <em>{t("db.zone.notOnMap")}</em>}
                      </span>
                      <span className="zn-edit" aria-hidden="true">›</span>
                    </button>
                  </li>
                ))}
              </ul>
              <div className="zn-add">
                <button type="button" className="btn-sm" onClick={startCircle} disabled={MAPS_KEY && !ready}>◯ {t("db.zone.addCircle")}</button>
                {MAPS_KEY && <button type="button" className="btn-sm" onClick={() => startDrawing()} disabled={!ready}>✏️ {t("db.zone.addShape")}</button>}
              </div>
              <p className="sset-hint">{t("db.zone.howItWorks")}</p>
            </div>
          )}
        </aside>
      </div>

      {confirm && (
        <ConfirmModal title={t("db.zone.delete")} message={<><strong>{confirm.name}</strong> — {t("db.zone.remove")}</>} danger
          onClose={() => setConfirm(null)}
          onConfirm={async () => {
            try { await api("DELETE", `/settings/zones/${confirm.id}`); setDraft(null); onChanged(); toast(t("db.saved")); }
            catch (e) { toast(e.message, "error"); }
          }} />
      )}
    </div>
  );
}

function RingRow({ r, onChange, onRemove, color }) {
  return (
    <>
      <span className="zn-ring-km"><i style={{ background: color }} /><input type="number" min="0.5" max="60" step="0.5" value={r.km} onChange={(e) => onChange({ km: e.target.value })} aria-label="km" /></span>
      <input type="number" min="0" step="0.5" value={r.fee} onChange={(e) => onChange({ fee: e.target.value })} placeholder="0" aria-label="fee" />
      <input type="number" min="0" step="0.5" value={r.min} onChange={(e) => onChange({ min: e.target.value })} placeholder="0" aria-label="minimum" />
      {onRemove ? <button type="button" className="zn-x" onClick={onRemove} aria-label="remove">✕</button> : <span />}
    </>
  );
}
