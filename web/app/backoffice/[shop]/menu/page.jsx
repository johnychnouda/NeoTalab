"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import FormSelect from "@/components/FormSelect";
import { useLiveRefresh } from "@/lib/live";
import { formatMoney, currencyLabel, toLbp, formatLbp } from "@/lib/money";
import { Modal, ConfirmModal, useToast } from "@/components/ui";
import { EmptyState, EMPTY_ICONS } from "@/components/owner/ui";

const today = () => new Date().toISOString().slice(0, 10);
const offerOn = (p) => Boolean(p.offer_active);
// Search ignores case, Arabic vowel marks and spelling variants (أ/إ/آ → ا, ة → ه, ى → ي).
const norm = (v) => String(v || "").toLowerCase().normalize("NFKD").replace(/[\u064B-\u065F\u0670\u0300-\u036f]/g, "")
  .replace(/[أإآ]/g, "ا").replace(/ة/g, "ه").replace(/ى/g, "ي").trim();

/**
 * The shop's menu / products / catalogue / services / price list, depending on its type of
 * business: categories, items with one name (any language), price and unit, sizes, option
 * groups (extras, colors…) and offers. Every item is shown in WhatsApp; delete to remove.
 */
export default function MenuPage() {
  const { api, shop } = useMerchant();
  const money = (v) => formatMoney(v, shop.currency);
  const { t } = useLang();
  const toast = useToast();

  const [categories, setCategories] = useState(null);
  const [products, setProducts] = useState([]);
  const [profile, setProfile] = useState({ word: "menu", option_suggestions: ["Extras"], units: [] });
  const [kind, setKind] = useState("restaurant");
  const [lbpRate, setLbpRate] = useState(null);
  const [error, setError] = useState("");
  const [modal, setModal] = useState(null);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState("all"); // "all", a category id, or "none"
  const [dragId, setDragId] = useState(null);
  const drag = useRef(null);
  const itemDrag = useRef(null);
  const [itemDragId, setItemDragId] = useState(null);
  const navRef = useRef(null);

  const load = useCallback(async () => {
    try {
      const [{ categories: cats }, { products: prods }, { profile: shop }] = await Promise.all([
        api("GET", "/categories"),
        api("GET", "/products?per_page=500"),
        api("GET", "/settings/profile"),
      ]);
      setCategories(cats);
      setProducts(prods);
      if (shop?.menu_profile) setProfile(shop.menu_profile);
      if (shop?.business_kind) setKind(shop.business_kind);
      setLbpRate(Number(shop?.lbp_rate) > 0 && shop?.currency !== "LBP" ? Number(shop.lbp_rate) : null);
    } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  async function run(fn, close = true) {
    try { await fn(); if (close) setModal(null); load(); }
    catch (e) { toast(e.message, "error"); }
  }

  const services = kind === "services";
  const allSections = useMemo(() => {
    if (!categories) return [];
    const list = categories.map((c) => ({ key: c.id, cat: c, items: products.filter((p) => p.category_id === c.id) }));
    const loose = products.filter((p) => !p.category_id || !categories.some((c) => c.id === p.category_id));
    if (loose.length) list.push({ key: "none", cat: null, items: loose });
    return list;
  }, [categories, products]);
  // A search looks through every category; otherwise the picked category (or all of them) shows.
  const sections = useMemo(() => {
    const q = norm(query);
    if (q) {
      return allSections
        .map((sec) => ({ ...sec, items: sec.items.filter((p) => norm(`${p.name} ${p.description || ""}`).includes(q)) }))
        .filter((sec) => sec.items.length > 0);
    }
    const picked = allSections.filter((sec) => active === "all" || sec.key === active);
    return picked.length ? picked : allSections;
  }, [allSections, query, active]);

  async function saveItem(item, vals) {
    const body = {
      name: vals.name, description: vals.description || null, price: vals.price, category_id: vals.categoryId || null,
      unit: vals.unit || null, duration_mins: services && vals.duration ? Number(vals.duration) : null,
      offer_percent: vals.offerPercent || null, offer_starts_on: vals.offerStartsOn || null, offer_ends_on: vals.offerEndsOn || null,
      staff_check: Boolean(vals.staffCheck),
    };
    let id = item?.id;
    if (id) {
      await api("PATCH", `/products/${id}`, body);
    } else {
      const res = await api("POST", "/products", body);
      id = res.products?.[0]?.id;
    }
    if (id && !services) {
      await api("PUT", `/products/${id}/options`, {
        variants: vals.sizes.filter((s) => s.name.trim()).map((s) => ({ id: s.id || null, name: s.name.trim(), price: String(s.price ?? "").trim() === "" ? null : Number(s.price) })),
        groups: vals.groups.filter((g) => g.name.trim() && g.modifiers.some((m) => m.name.trim())).map((g) => ({
          id: g.id || null, name: g.name.trim(), required: g.required, max_select: Number(g.max_select) || 1,
          modifiers: g.modifiers.filter((m) => m.name.trim()).map((m) => ({ id: m.id || null, name: m.name.trim(), price_delta: Number(m.price_delta) || 0 })),
        })),
      });
    }
    toast(t("mk.saved"));
  }

  // Drag a category to a new place (mouse: the whole row; touch: the grip, so the list still scrolls).
  // The order is saved for the dashboard and the WhatsApp menu.
  function dragStart(e, id) {
    if (e.pointerType !== "mouse" && !e.target.closest(".menu-nav-grip")) return;
    if (e.button !== undefined && e.button !== 0) return;
    drag.current = { id, x: e.clientX, y: e.clientY, moved: false, before: categories.map((c) => c.id) };
    e.currentTarget.setPointerCapture?.(e.pointerId);
  }
  function dragMove(e) {
    const d = drag.current;
    if (!d) return;
    if (!d.moved && Math.hypot(e.clientX - d.x, e.clientY - d.y) < 6) return;
    d.moved = true;
    setDragId(d.id);
    const rows = [...(navRef.current?.querySelectorAll("[data-cat]") || [])];
    const over = rows.map((el) => { const r = el.getBoundingClientRect(); return { id: el.dataset.cat, dist: Math.hypot(e.clientX - (r.left + r.width / 2), e.clientY - (r.top + r.height / 2)) }; })
      .sort((a, b) => a.dist - b.dist)[0];
    if (!over || over.id === d.id) return;
    setCategories((list) => {
      const from = list.findIndex((c) => c.id === d.id);
      const to = list.findIndex((c) => c.id === over.id);
      if (from < 0 || to < 0) return list;
      const next = [...list];
      next.splice(to, 0, next.splice(from, 1)[0]);
      return next;
    });
  }
  async function dragEnd() {
    const d = drag.current;
    drag.current = null;
    setDragId(null);
    if (!d?.moved) return;
    drag.lastMoved = Date.now();
    const ids = categories.map((c) => c.id);
    if (ids.join() === d.before.join()) return;
    try { await api("PUT", "/categories/order", { ids }); toast(t("mk.orderSaved")); }
    catch (err) { toast(err.message, "error"); load(); }
  }
  // Drag an item up or down inside its category (grip only, so a click still opens the editor).
  // The order is saved for the dashboard and the WhatsApp menu.
  function itemDragStart(e, p) {
    if (e.button !== undefined && e.button !== 0) return;
    const section = e.currentTarget.closest(".menu-category");
    itemDrag.current = {
      id: p.id, catId: p.category_id, x: e.clientX, y: e.clientY, moved: false,
      before: products.filter((x) => x.category_id === p.category_id).map((x) => x.id), section,
    };
    e.currentTarget.setPointerCapture?.(e.pointerId);
  }
  function itemDragMove(e) {
    const d = itemDrag.current;
    if (!d) return;
    if (!d.moved && Math.hypot(e.clientX - d.x, e.clientY - d.y) < 6) return;
    d.moved = true;
    setItemDragId(d.id);
    const rows = [...(d.section?.querySelectorAll("[data-prod]") || [])];
    const over = rows.map((el) => { const r = el.getBoundingClientRect(); return { id: el.dataset.prod, dist: Math.hypot(e.clientX - (r.left + r.width / 2), e.clientY - (r.top + r.height / 2)) }; })
      .sort((a, b) => a.dist - b.dist)[0];
    if (!over || over.id === d.id) return;
    setProducts((list) => {
      const from = list.findIndex((x) => x.id === d.id);
      const to = list.findIndex((x) => x.id === over.id);
      if (from < 0 || to < 0) return list;
      const next = [...list];
      next.splice(to, 0, next.splice(from, 1)[0]);
      return next;
    });
  }
  async function itemDragEnd() {
    const d = itemDrag.current;
    itemDrag.current = null;
    setItemDragId(null);
    if (!d?.moved) return;
    const ids = products.filter((x) => x.category_id === d.catId).map((x) => x.id);
    if (ids.join() === d.before.join()) return;
    try { await api("PUT", "/products/order", { ids }); toast(t("mk.orderSaved")); }
    catch (err) { toast(err.message, "error"); load(); }
  }
  const pick = (key) => {
    if (drag.lastMoved && Date.now() - drag.lastMoved < 250) return; // the click that ends a drag
    setActive(key); setQuery("");
  };

  // The same columns Import reads, so a shop can edit the file in Excel and import it back.
  function exportCsv() {
    const cell = (v) => {
      const text = String(v ?? "");
      return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };
    const rows = [["Category", "Name", "Price", "Description"]];
    if (lbpRate) rows[0].push("LBP");
    allSections.forEach(({ cat, items }) => items.forEach((p) => {
      const row = [cat ? cat.name : "", p.name, Number(p.price).toFixed(2), p.description || ""];
      if (lbpRate) row.push(toLbp(p.price, lbpRate));
      rows.push(row);
    }));
    // BOM so Excel opens Arabic names correctly.
    const blob = new Blob(["\uFEFF" + rows.map((r) => r.map(cell).join(",")).join("\r\n")], { type: "text/csv;charset=utf-8" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `${shop.slug || "menu"}-menu.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  }

  return (
    <div>
      <div className="page-header menu-head">
        <div>
          <h1>{t(`mk.title.${profile.word}`)}</h1>
          {products.length > 0 && <p className="menu-sub">{products.length} {t("mk.itemsCount")} · {categories?.length || 0} {t("mk.categoriesCount")}</p>}
        </div>
        <div className="menu-head-actions">
          {!services && <button className="btn-sm" onClick={() => setModal({ type: "import" })}>{t("mk.import")}</button>}
          {products.length > 0 && <button className="btn-sm" onClick={exportCsv}>{t("mk.export")}</button>}
          <button className="btn-sm" onClick={() => setModal({ type: "category" })}>+ {t("mk.addCategory")}</button>
          <button className="btn-primary" onClick={() => setModal({ type: "item", categoryId: active !== "all" && active !== "none" ? active : undefined })}>+ {t(services ? "mk.addService" : "mk.addItem")}</button>
        </div>
      </div>

      {error && <div style={{ color: "var(--red)" }}>{error}</div>}
      {categories === null && !error && <div className="loader">…</div>}
      {categories && categories.length === 0 && products.length === 0 && (
        <EmptyState icon={EMPTY_ICONS.menu} title={t("mk.empty")} />
      )}

      {allSections.length > 0 && (
        <div className="menu-layout">
          <aside className="menu-nav" ref={navRef} aria-label={t("mk.category")}>
            <button type="button" className={`menu-nav-item ${active === "all" && !query ? "is-on" : ""}`} onClick={() => pick("all")}>
              <span>{t("mk.all")}</span><span className="menu-nav-count">{products.length}</span>
            </button>
            {allSections.map((sec) => sec.cat ? (
              <button type="button" key={sec.key} data-cat={sec.key}
                className={`menu-nav-item is-draggable ${active === sec.key && !query ? "is-on" : ""} ${dragId === sec.key ? "is-dragging" : ""}`}
                onClick={() => pick(sec.key)} onPointerDown={(e) => dragStart(e, sec.key)} onPointerMove={dragMove} onPointerUp={dragEnd} onPointerCancel={dragEnd}
                title={t("mk.dragHint")}>
                <span className="menu-nav-grip" aria-hidden="true">⋮⋮</span>
                <span className="menu-nav-name">{sec.cat.name}</span><span className="menu-nav-count">{sec.items.length}</span>
              </button>
            ) : (
              <button type="button" key={sec.key} className={`menu-nav-item ${active === sec.key && !query ? "is-on" : ""}`} onClick={() => pick(sec.key)}>
                <span className="menu-nav-name">{t("mk.noCategory")}</span><span className="menu-nav-count">{sec.items.length}</span>
              </button>
            ))}
            {products.length > 0 && <button type="button" className="menu-clear" onClick={() => setModal({ type: "clear" })}>{t("mk.clear")}</button>}
          </aside>

          <div className="menu-main">
            <div className="menu-search">
              <input type="search" className="search-input" value={query} onChange={(e) => setQuery(e.target.value)}
                placeholder={t("mk.search")} aria-label={t("mk.search")} />
              {query && <span className="menu-search-count">{sections.reduce((n, sec) => n + sec.items.length, 0)} {t("mk.itemsCount")}</span>}
            </div>
            {query && sections.length === 0 && <p className="menu-search-empty">{t("mk.noMatch")}</p>}

            <div className="menu-list">
              {sections.map(({ key, cat, items }) => (
                <section className="menu-category" key={key}>
                  <header className="menu-cat-header">
                    <h2 className="menu-cat-name">{cat ? cat.name : t("mk.noCategory")}</h2>
                    <span className="menu-cat-count">{items.length}</span>
                    <div className="menu-cat-actions">
                      {cat ? <>
                        <button className="icon-btn" title={t("mk.addItem")} aria-label={t("mk.addItem")} onClick={() => setModal({ type: "item", categoryId: cat.id })}>{ICONS.plus}</button>
                        <button className="icon-btn" title={t("mk.edit")} aria-label={t("mk.edit")} onClick={() => setModal({ type: "category", cat })}>{ICONS.edit}</button>
                        <button className="icon-btn is-danger" title={t("mk.delete")} aria-label={t("mk.delete")} onClick={() => setModal({ type: "deleteCategory", cat })}>{ICONS.trash}</button>
                      </> : items.length > 0 && (
                        <button className="icon-btn is-danger" title={t("mk.deleteAll")} aria-label={t("mk.deleteAll")} onClick={() => setModal({ type: "clearUncategorized", count: items.length })}>{ICONS.trash}</button>
                      )}
                    </div>
                  </header>
                  {items.length === 0 && (
                    <button type="button" className="menu-cat-empty" onClick={() => setModal({ type: "item", categoryId: cat?.id })}>+ {t("mk.addItem")}</button>
                  )}
                  {items.map((p) => {
                    const sizesN = p.variants?.length || 0;
                    const extrasN = (p.modifier_groups || []).reduce((n, g) => n + (g.modifiers?.length || 0), 0);
                    return (
                      <div key={p.id} data-prod={p.id} className={`product-row ${itemDragId === p.id ? "is-dragging" : ""}`} role="button" tabIndex={0}
                        onClick={() => setModal({ type: "item", item: p })}
                        onKeyDown={(e) => { if (e.key === "Enter") setModal({ type: "item", item: p }); }}>
                        {cat && !query && items.length > 1 && (
                          <span className="product-grip" title={t("mk.dragHint")} aria-hidden="true"
                            onClick={(e) => e.stopPropagation()}
                            onPointerDown={(e) => itemDragStart(e, p)} onPointerMove={itemDragMove}
                            onPointerUp={itemDragEnd} onPointerCancel={itemDragEnd}>⋮⋮</span>
                        )}
                        <div className="product-info">
                          <span className="product-name">{p.name}</span>
                          {p.description && <span className="product-desc">{p.description}</span>}
                          {(sizesN > 0 || extrasN > 0 || p.offer_percent || (services && p.duration_mins)) ? (
                            <span className="product-tags">
                              {sizesN > 0 && <span className="tag">{sizesN} {t("mk.sizesCount")}</span>}
                              {extrasN > 0 && <span className="tag">{extrasN} {t("mk.optionsCount")}</span>}
                              {p.offer_percent ? <span className={`tag ${offerOn(p) ? "tag-green" : ""}`}>-{p.offer_percent}%</span> : null}
                              {services && p.duration_mins ? <span className="tag">{p.duration_mins} min</span> : null}
                            </span>
                          ) : null}
                        </div>
                        <div className="product-price">
                          <span>{money(p.price)}{p.unit ? ` / ${t(`mk.unit.${p.unit}`)}` : ""}</span>
                          {lbpRate && <small className="product-lbp">{formatLbp(toLbp(p.price, lbpRate))}</small>}
                        </div>
                        <button type="button" className="icon-btn is-danger product-del" title={t("mk.delete")} aria-label={t("mk.delete")}
                          onClick={(e) => { e.stopPropagation(); setModal({ type: "deleteItem", item: p }); }}>{ICONS.trash}</button>
                      </div>
                    );
                  })}
                </section>
              ))}
            </div>
            {products.length > 0 && !query && <button type="button" className="menu-clear menu-clear-phone" onClick={() => setModal({ type: "clear" })}>{t("mk.clear")}</button>}
          </div>
        </div>
      )}

      {modal?.type === "category" && (
        <CategoryModal t={t} initial={modal.cat} onClose={() => setModal(null)}
          onSubmit={(name) => run(() => (modal.cat
            ? api("PATCH", `/categories/${modal.cat.id}`, { name })
            : api("POST", "/categories", { name })))} />
      )}
      {modal?.type === "deleteCategory" && (
        <Modal title={t("mk.delete")} onClose={() => setModal(null)}>
          <div style={{ color: "var(--text-muted)", fontSize: 14, lineHeight: 1.5, marginBottom: 20 }}>
            <strong>{modal.cat.name}</strong> — {t("mk.deleteCategoryAsk")}
          </div>
          <div className="modal-actions">
            <button className="btn-sm" onClick={() => { setModal(null); run(() => api("DELETE", `/categories/${modal.cat.id}`)); }}>{t("mk.keepItems")}</button>
            <button className="btn-danger" onClick={() => { setModal(null); run(() => api("DELETE", `/categories/${modal.cat.id}?with_items=1`)); }}>{t("mk.withItems")}</button>
          </div>
        </Modal>
      )}
      {modal?.type === "clearUncategorized" && (
        <ConfirmModal title={t("mk.deleteAll")} message={<><strong>{modal.count}</strong> {t("mk.deleteAllNone")}</>}
          danger confirmLabel={t("mk.deleteAll")} onClose={() => setModal(null)}
          onConfirm={() => run(() => api("DELETE", "/menu?only=uncategorized"))} />
      )}
      {modal?.type === "clear" && (
        <ConfirmModal title={t("mk.clear")} message={t("mk.clearAsk")}
          danger confirmLabel={t("mk.clear")} onClose={() => setModal(null)}
          onConfirm={() => run(() => api("DELETE", "/menu"))} />
      )}
      {modal?.type === "deleteItem" && (
        <ConfirmModal title={t("mk.delete")} message={<><strong>{modal.item.name}</strong> — {t("mk.deleteItem")}</>}
          danger onClose={() => setModal(null)}
          onConfirm={() => run(() => api("DELETE", `/products/${modal.item.id}`))} />
      )}
      {modal?.type === "item" && (
        <ItemModal t={t} currency={shop.currency} rate={lbpRate} item={modal.item} categories={categories || []} defaultCategoryId={modal.categoryId}
          profile={profile} services={services} onClose={() => setModal(null)}
          onSubmit={(vals) => {
            if (!vals.name || Number.isNaN(vals.price)) { toast(t("mk.required_name_price"), "error"); return; }
            run(() => saveItem(modal.item, vals));
          }} />
      )}
      {modal?.type === "import" && (
        <ImportMenuModal api={api} toast={toast} onClose={() => setModal(null)} onDone={() => { setModal(null); load(); }} />
      )}
    </div>
  );
}

function CategoryModal({ t, initial, onClose, onSubmit }) {
  const [name, setName] = useState(initial?.name || "");
  return (
    <Modal title={initial ? t("mk.edit") : t("mk.addCategory")} onClose={onClose} compact
      footer={<div className="modal-footer-actions">
        <button type="button" className="btn-sm" onClick={onClose}>{t("mk.cancel")}</button>
        <button type="button" className="btn-primary" onClick={() => name.trim() && onSubmit(name.trim())}>{t("mk.save")}</button>
      </div>}>
      <div className="field"><label htmlFor="cat-name">{t("mk.name")}</label>
        <input id="cat-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Sandwiches · سندويشات" autoFocus /></div>
    </Modal>
  );
}

const ICONS = {
  plus: <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>,
  edit: <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>,
  trash: <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>,
};

const ROW_ICONS = {
  sizes: <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M3 17h18M6 17V7M10 17v-6M14 17V9M18 17v-4" /></svg>,
  options: <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 8v8M8 12h8" /></svg>,
  offer: <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z" /><circle cx="7.5" cy="7.5" r="1.5" /></svg>,
};

/** A price in the shop currency with its lira twin: type in either, the other follows (1,000 LBP steps). */
function PriceInput({ value, onChange, rate, currency, compact = false, label, placeholder = "0.00" }) {
  const [lbpText, setLbpText] = useState(null);
  const lbp = toLbp(value, rate);
  const shown = lbpText ?? (lbp === null ? "" : lbp.toLocaleString("en-US"));
  const typeLbp = (raw) => {
    const digits = raw.replace(/[^0-9]/g, "");
    setLbpText(digits ? Number(digits).toLocaleString("en-US") : "");
    onChange(digits ? String(Math.round((Number(digits) / rate) * 100) / 100) : "");
  };
  return (
    <div className={`price-pair ${compact ? "is-compact" : ""} ${rate ? "" : "is-single"}`}>
      <label className="price-box">
        <input type="number" inputMode="decimal" step="0.01" min="0" value={value} placeholder={placeholder}
          onChange={(e) => { setLbpText(null); onChange(e.target.value); }} aria-label={label} />
        <span className="price-unit">{currencyLabel(currency)}</span>
      </label>
      {rate && <>
        <span className="price-eq" aria-hidden="true">=</span>
        <label className="price-box">
          <input type="text" inputMode="numeric" value={shown} placeholder="0" onBlur={() => setLbpText(null)}
            onChange={(e) => typeLbp(e.target.value)} aria-label={`${label} LBP`} />
          <span className="price-unit">LBP</span>
        </label>
      </>}
    </div>
  );
}

function ItemModal({ t, currency, rate, item, categories, defaultCategoryId, profile, services, onClose, onSubmit }) {
  const [name, setName] = useState(item?.name || "");
  const [price, setPrice] = useState(item?.price ?? "");
  const [description, setDescription] = useState(item?.description || "");
  const [categoryId, setCategoryId] = useState(item?.category_id || defaultCategoryId || "");
  const [unit, setUnit] = useState(item?.unit || "");
  const [duration, setDuration] = useState(item?.duration_mins ?? 30);
  const [sizes, setSizes] = useState((item?.variants || []).map((v) => ({ id: v.id, name: v.name, price: v.price ?? "" })));
  const [groups, setGroups] = useState((item?.modifier_groups || []).map((g) => ({
    id: g.id, name: g.name, required: Boolean(g.required), max_select: g.max_select || 1,
    modifiers: (g.modifiers || []).map((m) => ({ id: m.id, name: m.name, price_delta: m.price_delta ?? "" })),
  })));
  const [offerPercent, setOfferPercent] = useState(item?.offer_percent ?? "");
  const [offerStartsOn, setOfferStartsOn] = useState(item?.offer_starts_on || "");
  const [offerEndsOn, setOfferEndsOn] = useState(item?.offer_ends_on || "");
  const [staffCheck, setStaffCheck] = useState(Boolean(item?.staff_check));

  const setGroup = (i, patch) => setGroups(groups.map((g, j) => (j === i ? { ...g, ...patch } : g)));
  const setChoice = (i, k, patch) => setGroup(i, { modifiers: groups[i].modifiers.map((m, j) => (j === k ? { ...m, ...patch } : m)) });
  const addGroup = (groupName = "") => setGroups([...groups, { name: groupName, required: false, max_select: 1, modifiers: [{ name: "", price_delta: "" }] }]);
  const suggestions = (profile.option_suggestions || []).map((s) => t(`mk.sug.${s}`))
    .filter((s) => !groups.some((g) => g.name.toLowerCase() === s.toLowerCase()));

  // What the customer reads in WhatsApp: "3.91$ (350,000 LBP)", with the offer price when there is one.
  const both = (v) => {
    const lbp = toLbp(v, rate);
    return `${formatMoney(v, currency)}${lbp !== null ? ` (${formatLbp(lbp)})` : ""}`;
  };
  const pct = parseInt(offerPercent, 10) || 0;
  const hasPrice = price !== "" && !Number.isNaN(Number(price));
  const offerPrice = hasPrice && pct > 0 ? Math.round(Number(price) * (100 - pct)) / 100 : null;

  // One page: the essentials, plus a tile per extra that opens in place (← back).
  const [view, setView] = useState("main");
  // "Sold by" only where shops sell by weight or pack; a restaurant only sells items.
  const unitOptions = [...new Set([...(profile.units || []), ...(unit ? [unit] : [])])];
  const plain = (key) => t(key).replace(/^\p{Extended_Pictographic}\s*/u, "");
  const filledSizes = sizes.filter((s) => s.name.trim());
  const filledGroups = groups.filter((g) => g.name.trim());
  // Food shops call their option groups "Extras"; shops selling colors or services say "Options".
  const optionsWord = profile.word === "menu" ? t("mk.extras") : t("mk.options");
  const tiles = services ? [
    { key: "offer", label: plain("mk.offer"), hint: t("mk.offerShort"), value: pct > 0 ? `-${pct}%` : "" },
  ] : [
    { key: "sizes", label: t("mk.sizes"), hint: t("mk.sizesShort"), value: filledSizes.map((s) => s.name.trim()).join(", ") },
    { key: "options", label: optionsWord, hint: t("mk.optionsShort"), value: filledGroups.flatMap((g) => g.modifiers.map((m) => m.name.trim()).filter(Boolean)).join(", ") },
    { key: "offer", label: plain("mk.offer"), hint: t("mk.offerShort"), value: pct > 0 ? `-${pct}%${offerEndsOn ? ` · ${t("mk.offerTo")} ${offerEndsOn}` : ""}` : "" },
  ];
  const current = tiles.find((x) => x.key === view);
  const save = () => {
    if (!name.trim() || !hasPrice) setView("main");
    onSubmit({
      name: name.trim(), price: parseFloat(price), description: description.trim(), categoryId, unit,
      duration: parseInt(duration, 10) || null, sizes, groups,
      offerPercent: parseInt(offerPercent, 10) || null, offerStartsOn, offerEndsOn, staffCheck,
    });
  };

  return (
    <Modal title={item ? t("mk.editItem") : t(services ? "mk.addService" : "mk.addItem")} onClose={onClose} className="modal-editor"
      footer={<div className="modal-footer-actions">
        <button type="button" className="btn-sm" onClick={onClose}>{t("mk.cancel")}</button>
        <button type="button" className="btn-primary" onClick={save}>{t("mk.save")}</button>
      </div>}>
      <div className="ie">
        {view === "main" ? (
          <div className="ie-main">
            <div className="ie-fields">
              <div className="field">
                <label htmlFor="it-name">{t("mk.name")}</label>
                <input id="it-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Shawarma · شاورما" autoFocus />
              </div>
              <div className="field">
                <label>{t("mk.price")}</label>
                <PriceInput value={price} onChange={setPrice} rate={rate} currency={currency} label={t("mk.price")} />
              </div>
              <div className={unitOptions.length || services ? "ie-two" : ""}>
                <div className="field">
                  <label htmlFor="it-cat">{t("mk.category")}</label>
                  <FormSelect id="it-cat" value={categoryId} onChange={setCategoryId}
                    options={[{ value: "", label: t("mk.noCategory") }, ...categories.map((c) => ({ value: c.id, label: c.name }))]} />
                </div>
                {services ? (
                  <div className="field">
                    <label htmlFor="it-dur">{t("mk.duration")}</label>
                    <input id="it-dur" type="number" min="5" max="1440" value={duration} onChange={(e) => setDuration(e.target.value)} />
                  </div>
                ) : unitOptions.length > 0 && (
                  <div className="field">
                    <label htmlFor="it-unit">{t("mk.unit")}</label>
                    <FormSelect id="it-unit" value={unit} onChange={setUnit}
                      options={[{ value: "", label: t("mk.unit.none") }, ...unitOptions.map((u) => ({ value: u, label: t(`mk.unit.${u}`) }))]} />
                  </div>
                )}
              </div>
              <div className="field">
                <label htmlFor="it-desc">{t("mk.description")}</label>
                <textarea id="it-desc" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} placeholder={t("mk.descHint")} />
              </div>
              {!services && (
                <div className="field">
                  <label className="mk-check"><input type="checkbox" checked={staffCheck} onChange={(e) => setStaffCheck(e.target.checked)} /> {t("mk.staffCheck")}</label>
                  {staffCheck && <p className="mk-hint">{t("mk.staffCheckHint")}</p>}
                </div>
              )}
            </div>

            <div className="ie-side">
              <div className="ie-side-title">{t("mk.optional")}</div>
              {tiles.map((x) => (
                <button key={x.key} type="button" className={`ie-row ${x.value ? "is-set" : ""}`} onClick={() => setView(x.key)}>
                  <span className="ie-row-icon" aria-hidden="true">{ROW_ICONS[x.key]}</span>
                  <span className="ie-row-text">
                    <span className="ie-row-label">{x.label}</span>
                    <span className="ie-row-value">{x.value || x.hint}</span>
                  </span>
                  <span className="ie-row-go" aria-hidden="true">{x.value ? t("mk.edit") : `+ ${t("mk.add")}`}</span>
                </button>
              ))}
            </div>
          </div>
        ) : (
          <div className="ie-sub">
            <button type="button" className="ie-back" onClick={() => setView("main")}>
              <span aria-hidden="true">‹</span> {current?.label}
            </button>


            {view === "sizes" && (
              <div className="ie-list">
                <p className="mk-hint">{t("mk.sizesHint")}</p>
                {sizes.map((s, i) => (
                  <div className="ie-line" key={s.id || `n${i}`}>
                    <input value={s.name} onChange={(e) => setSizes(sizes.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} placeholder={t("mk.sizeName")} aria-label={t("mk.sizeName")} />
                    <PriceInput compact value={s.price} rate={rate} currency={currency} label={t("mk.price")}
                      onChange={(v) => setSizes(sizes.map((x, j) => (j === i ? { ...x, price: v } : x)))} />
                    <button type="button" className="ie-x" onClick={() => setSizes(sizes.filter((_, j) => j !== i))} aria-label={t("mk.delete")}>✕</button>
                  </div>
                ))}
                <button type="button" className="ie-add" onClick={() => setSizes([...sizes, { name: "", price: price || "" }])}>{t("mk.addSize")}</button>
              </div>
            )}

            {view === "options" && (
              <div className="ie-list">
                <p className="mk-hint">{t("mk.optionsHint")}</p>
                {groups.map((g, i) => (
                  <div className="ie-group" key={g.id || `g${i}`}>
                    <div className="ie-line">
                      <input value={g.name} onChange={(e) => setGroup(i, { name: e.target.value })} placeholder={t("mk.groupName")} aria-label={t("mk.groupName")} className="ie-strong" />
                      <button type="button" className="ie-x" onClick={() => setGroups(groups.filter((_, j) => j !== i))} aria-label={t("mk.delete")}>✕</button>
                    </div>
                    <div className="ie-rules">
                      <label className="mk-check"><input type="checkbox" checked={g.required} onChange={(e) => setGroup(i, { required: e.target.checked })} /> {t("mk.required")}</label>
                      <label className="mk-check">{t("mk.maxChoices")} <input type="number" min="1" max="20" value={g.max_select} onChange={(e) => setGroup(i, { max_select: e.target.value })} className="mk-num" /></label>
                    </div>
                    {g.modifiers.map((m, k) => (
                      <div className="ie-line" key={m.id || `m${k}`}>
                        <input value={m.name} onChange={(e) => setChoice(i, k, { name: e.target.value })} placeholder={t("mk.choice")} aria-label={t("mk.choice")} />
                        <PriceInput compact value={m.price_delta} rate={rate} currency={currency} label={t("mk.extraPrice")} placeholder="+0"
                          onChange={(v) => setChoice(i, k, { price_delta: v })} />
                        <button type="button" className="ie-x" onClick={() => setGroup(i, { modifiers: g.modifiers.filter((_, j) => j !== k) })} aria-label={t("mk.delete")}>✕</button>
                      </div>
                    ))}
                    <button type="button" className="ie-add ie-add-sm" onClick={() => setGroup(i, { modifiers: [...g.modifiers, { name: "", price_delta: "" }] })}>{t("mk.addChoice")}</button>
                  </div>
                ))}
                <div className="ie-chips">
                  <button type="button" className="ie-add" onClick={() => addGroup()}>{t("mk.addGroup")}</button>
                  {suggestions.map((s) => <button type="button" key={s} className="ie-chip" onClick={() => addGroup(s)}>+ {s}</button>)}
                </div>
              </div>
            )}

            {view === "offer" && (
              <div className="ie-list">
                <p className="mk-hint">{t("mk.offerHint")}</p>
                <div className="field">
                  <label htmlFor="it-off">{t("mk.offerPercent")}</label>
                  <label className="price-box"><input id="it-off" type="number" min="1" max="90" value={offerPercent} placeholder="0" onChange={(e) => setOfferPercent(e.target.value)} /><span className="price-unit">%</span></label>
                </div>
                <div className="ie-two">
                  <div className="field">
                    <label htmlFor="it-from">{t("mk.offerFrom")}</label>
                    <input id="it-from" type="date" value={offerStartsOn} min={item ? undefined : today()} onChange={(e) => setOfferStartsOn(e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="it-to">{t("mk.offerTo")}</label>
                    <input id="it-to" type="date" value={offerEndsOn} min={offerStartsOn || undefined} onChange={(e) => setOfferEndsOn(e.target.value)} />
                  </div>
                </div>
                {offerPrice !== null && <div className="ie-offer-note">{both(price)} → <strong>{both(offerPrice)}</strong></div>}
              </div>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

function ImportMenuModal({ api, toast, onClose, onDone }) {
  const { t } = useLang();
  const [items, setItems] = useState(null);
  const [busy, setBusy] = useState(false);
  const [lbpRate, setLbpRate] = useState(null);

  async function upload(file) {
    if (!file) return;
    const form = new FormData();
    form.append("file", file);
    setBusy(true);
    try {
      const data = await api("POST", "/menu/import/preview", form);
      // Each row carries its own currency: one lira row never turns the dollar rows into lira.
      setItems((data.items || []).map((it) => ({ ...it, lbp: !!it.lbp })));
      setLbpRate(data.lbp_rate || null);
    } catch (e) { toast(e.message, "error"); } finally { setBusy(false); }
  }

  const edit = (i, patch) => setItems(items.map((x, j) => (j === i ? { ...x, ...patch } : x)));
  const setAllLbp = (lbp) => setItems(items.map((x) => ({ ...x, lbp })));
  // The price as it will be saved; a row that comes out at 0.00 blocks the import.
  const saved = (it) => Math.round((Number(it.price) / (it.lbp && lbpRate ? lbpRate : 1)) * 100) / 100;
  const zeroRows = (items || []).map((it, i) => (saved(it) >= 0.01 ? null : i + 1)).filter(Boolean);
  const allLbp = !!items?.length && items.every((it) => it.lbp);
  const anyLbp = !!items?.some((it) => it.lbp);

  async function confirm() {
    setBusy(true);
    try {
      const { result } = await api("POST", "/menu/import", { items });
      toast(`${t("mk.imp.done")} ${result.created + result.updated}`);
      onDone();
    } catch (e) { toast(e.message, "error"); } finally { setBusy(false); }
  }

  return (
    <Modal title={t("mk.import")} onClose={onClose}>
      {items === null ? (
        <>
          <p className="mk-hint" style={{ fontSize: 13, lineHeight: 1.6, marginBottom: 14 }}>{t("mk.imp.intro")}</p>
          <label className={`mk-file ${busy ? "is-busy" : ""}`}>
            <input type="file" accept=".xlsx,.csv,.jpg,.jpeg,.png,.webp" disabled={busy}
              onChange={(e) => upload(e.target.files?.[0])} />
            <span aria-hidden="true">📄</span> {t("mk.imp.choose")}
            <small>Excel · CSV · JPG · PNG</small>
          </label>
          {busy && <div className="loader" style={{ marginTop: 12 }}>{t("mk.imp.reading")}</div>}
        </>
      ) : (
        <>
          <p style={{ fontSize: 13, marginBottom: 10 }}>{items.length} {t("mk.imp.found")} · {t("mk.imp.check")}</p>
          {lbpRate && (
            <div className="mk-row" style={{ alignItems: "center", gap: 8, marginBottom: 10, fontSize: 13 }}>
              <span>{t("mk.imp.pricesIn")}</span>
              <button className={anyLbp ? "btn-sm" : "btn-primary"} onClick={() => setAllLbp(false)}>$</button>
              <button className={allLbp ? "btn-primary" : "btn-sm"} onClick={() => setAllLbp(true)}>LBP</button>
              {anyLbp && <span className="mk-hint">{t("mk.imp.lbpNote")} {Number(lbpRate).toLocaleString("en-US")} LBP</span>}
            </div>
          )}
          <div className="table-scroll" style={{ maxHeight: "50vh", overflowY: "auto" }}>
            <table className="data-table stack-on-phone">
              <thead><tr><th>{t("mk.category")}</th><th>{t("mk.name")}</th><th>{t("mk.price")}</th><th></th></tr></thead>
              <tbody>
                {items.map((it, i) => (
                  <tr key={i}>
                    <td data-label={t("mk.category")}><input className="mk-cell" value={it.category || ""} aria-label={t("mk.category")} onChange={(e) => edit(i, { category: e.target.value })} /></td>
                    <td data-label={t("mk.name")}><input className="mk-cell" value={it.name || ""} aria-label={t("mk.name")} onChange={(e) => edit(i, { name: e.target.value })} /></td>
                    <td data-label={t("mk.price")}><input className="mk-cell mk-cell-num" type="number" step="0.01" min="0" value={it.price ?? ""} aria-label={t("mk.price")} aria-invalid={zeroRows.includes(i + 1) || undefined}
                      style={zeroRows.includes(i + 1) ? { borderColor: "var(--red)" } : undefined} onChange={(e) => edit(i, { price: e.target.value })} />
                      {lbpRate && <div style={{ marginTop: 4 }}>
                        <button type="button" className={it.lbp ? "btn-primary" : "btn-sm"} style={{ padding: "2px 8px", fontSize: 12 }} title={t("mk.imp.rowCur")} aria-label={t("mk.imp.rowCur")}
                          onClick={() => edit(i, { lbp: !it.lbp })}>{it.lbp ? "LBP" : "$"}</button>
                        {it.lbp && <small className="mk-hint"> {formatLbp(Number(it.price))} = {formatMoney(saved(it))}</small>}
                      </div>}</td>
                    <td className="stack-actions"><button className="btn-sm" onClick={() => setItems(items.filter((_, j) => j !== i))} aria-label={t("mk.delete")}>✕</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {zeroRows.length > 0 && <p role="alert" style={{ marginTop: 10, fontSize: 13, color: "var(--red)" }}>{t("mk.imp.zero")} {zeroRows.join(", ")}</p>}
          <div className="modal-actions">
            <button className="btn-sm" onClick={() => setItems(null)} disabled={busy}>{t("mk.cancel")}</button>
            <button className="btn-primary" onClick={confirm} disabled={busy || items.length === 0 || zeroRows.length > 0}>
              {busy ? t("db.loading") : `${t("mk.import")} (${items.length})`}
            </button>
          </div>
        </>
      )}
    </Modal>
  );
}
