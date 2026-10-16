"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";
import { formatMoney, currencyLabel } from "@/lib/money";
import { Modal, ConfirmModal, useToast } from "@/components/ui";
import { EmptyState, EMPTY_ICONS } from "@/components/owner/ui";

const today = () => new Date().toISOString().slice(0, 10);
const offerOn = (p) => Boolean(p.offer_active);

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
  const [profile, setProfile] = useState({ word: "menu", option_suggestions: ["Size", "Extras"], units: [] });
  const [kind, setKind] = useState("restaurant");
  const [error, setError] = useState("");
  const [modal, setModal] = useState(null);

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
    } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  async function run(fn, close = true) {
    try { await fn(); if (close) setModal(null); load(); }
    catch (e) { toast(e.message, "error"); }
  }

  const services = kind === "services";
  const sections = useMemo(() => {
    if (!categories) return [];
    const list = categories.map((c) => ({ cat: c, items: products.filter((p) => p.category_id === c.id) }));
    const loose = products.filter((p) => !p.category_id || !categories.some((c) => c.id === p.category_id));
    if (loose.length) list.push({ cat: null, items: loose });
    return list;
  }, [categories, products]);

  async function saveItem(item, vals) {
    const body = {
      name: vals.name, description: vals.description || null, price: vals.price, category_id: vals.categoryId || null,
      unit: vals.unit || null, duration_mins: services && vals.duration ? Number(vals.duration) : null,
      offer_percent: vals.offerPercent || null, offer_starts_on: vals.offerStartsOn || null, offer_ends_on: vals.offerEndsOn || null,
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
        variants: vals.sizes.filter((s) => s.name.trim()).map((s) => ({ id: s.id || null, name: s.name.trim(), price: Number(s.price) || 0 })),
        groups: vals.groups.filter((g) => g.name.trim() && g.modifiers.some((m) => m.name.trim())).map((g) => ({
          id: g.id || null, name: g.name.trim(), required: g.required, max_select: Number(g.max_select) || 1,
          modifiers: g.modifiers.filter((m) => m.name.trim()).map((m) => ({ id: m.id || null, name: m.name.trim(), price_delta: Number(m.price_delta) || 0 })),
        })),
      });
    }
    toast(t("mk.saved"));
  }

  return (
    <div>
      <div className="page-header">
        <h1>{t(`mk.title.${profile.word}`)}</h1>
        <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
          {!services && <button className="btn-sm" onClick={() => setModal({ type: "import" })}>{t("mk.import")}</button>}
          <button className="btn-sm" onClick={() => setModal({ type: "category" })}>+ {t("mk.addCategory")}</button>
          <button className="btn-primary" onClick={() => setModal({ type: "item" })}>+ {t(services ? "mk.addService" : "mk.addItem")}</button>
        </div>
      </div>

      {error && <div style={{ color: "var(--red)" }}>{error}</div>}
      {categories === null && !error && <div className="loader">…</div>}
      {categories && categories.length === 0 && products.length === 0 && (
        <EmptyState icon={EMPTY_ICONS.menu} title={t("mk.empty")} />
      )}

      <div className="menu-list">
        {sections.map(({ cat, items }) => (
          <div className="menu-category" key={cat?.id || "none"}>
            <div className="menu-cat-header">
              <span className="menu-cat-name">{cat ? cat.name : t("mk.noCategory")}</span>
              <span className="menu-cat-count">{items.length} {t("mk.itemsCount")}</span>
              {cat && <>
                <button className="btn-sm" onClick={() => setModal({ type: "item", categoryId: cat.id })}>+</button>
                <button className="btn-sm" onClick={() => setModal({ type: "category", cat })}>{t("mk.edit")}</button>
                <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "deleteCategory", cat })}>{t("mk.delete")}</button>
              </>}
            </div>
            {items.map((p) => (
              <div key={p.id} className="product-row">
                <div className="product-info">
                  <span className="product-name">{p.name}</span>
                  {p.description && <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{p.description}</span>}
                </div>
                <div className="product-meta">
                  <span className="product-price">{money(p.price)}{p.unit ? ` / ${t(`mk.unit.${p.unit}`)}` : ""}</span>
                  {p.variants?.length > 0 && <span className="pill">{p.variants.length} {t("mk.sizesCount")}</span>}
                  {p.modifier_groups?.length > 0 && <span className="pill">{p.modifier_groups.reduce((n, g) => n + (g.modifiers?.length || 0), 0)} {t("mk.optionsCount")}</span>}
                  {p.offer_percent ? <span className={`pill ${offerOn(p) ? "pill-active" : "pill-pending"}`}>-{p.offer_percent}%{p.offer_ends_on ? ` → ${p.offer_ends_on}` : ""}</span> : null}
                  {services && p.duration_mins ? <span className="pill">⏱ {p.duration_mins}m</span> : null}
                </div>
                <div className="product-actions">
                  <button className="btn-sm" onClick={() => setModal({ type: "item", item: p })}>{t("mk.edit")}</button>
                  <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "deleteItem", item: p })} aria-label={t("mk.delete")}>✕</button>
                </div>
              </div>
            ))}
          </div>
        ))}
      </div>

      {modal?.type === "category" && (
        <CategoryModal t={t} initial={modal.cat} onClose={() => setModal(null)}
          onSubmit={(name) => run(() => (modal.cat
            ? api("PATCH", `/categories/${modal.cat.id}`, { name })
            : api("POST", "/categories", { name })))} />
      )}
      {modal?.type === "deleteCategory" && (
        <ConfirmModal title={t("mk.delete")} message={<><strong>{modal.cat.name}</strong> — {t("mk.deleteCategory")}</>}
          danger onClose={() => setModal(null)}
          onConfirm={() => run(() => api("DELETE", `/categories/${modal.cat.id}`))} />
      )}
      {modal?.type === "deleteItem" && (
        <ConfirmModal title={t("mk.delete")} message={<><strong>{modal.item.name}</strong> — {t("mk.deleteItem")}</>}
          danger onClose={() => setModal(null)}
          onConfirm={() => run(() => api("DELETE", `/products/${modal.item.id}`))} />
      )}
      {modal?.type === "item" && (
        <ItemModal t={t} currency={shop.currency} item={modal.item} categories={categories || []} defaultCategoryId={modal.categoryId}
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

function ItemModal({ t, currency, item, categories, defaultCategoryId, profile, services, onClose, onSubmit }) {
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

  const setGroup = (i, patch) => setGroups(groups.map((g, j) => (j === i ? { ...g, ...patch } : g)));
  const setChoice = (i, k, patch) => setGroup(i, { modifiers: groups[i].modifiers.map((m, j) => (j === k ? { ...m, ...patch } : m)) });
  const addGroup = (groupName = "") => setGroups([...groups, { name: groupName, required: false, max_select: 1, modifiers: [{ name: "", price_delta: "" }] }]);
  const suggestions = (profile.option_suggestions || []).map((s) => t(`mk.sug.${s}`))
    .filter((s) => !groups.some((g) => g.name.toLowerCase() === s.toLowerCase()));

  return (
    <Modal title={item ? item.name : t(services ? "mk.addService" : "mk.addItem")} onClose={onClose} wide
      footer={<div className="modal-footer-actions">
        <button type="button" className="btn-sm" onClick={onClose}>{t("mk.cancel")}</button>
        <button type="button" className="btn-primary" onClick={() => onSubmit({
          name: name.trim(), price: parseFloat(price), description: description.trim(), categoryId, unit,
          duration: parseInt(duration, 10) || null, sizes, groups,
          offerPercent: parseInt(offerPercent, 10) || null, offerStartsOn, offerEndsOn,
        })}>{t("mk.save")}</button>
      </div>}>
      <div className="modal-form-grid">
        <div className="field span-2">
          <label htmlFor="it-name">{t("mk.name")}</label>
          <input id="it-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Shawarma · شاورما" autoFocus />
          <div className="mk-hint">{t("mk.nameHint")}</div>
        </div>
        <div className="field">
          <label htmlFor="it-price">{t("mk.price")} ({currencyLabel(currency)})</label>
          <input id="it-price" type="number" step="0.01" min="0" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="6.00" />
        </div>
        {services ? (
          <div className="field">
            <label htmlFor="it-dur">{t("mk.duration")}</label>
            <input id="it-dur" type="number" min="5" max="1440" value={duration} onChange={(e) => setDuration(e.target.value)} />
          </div>
        ) : (
          <div className="field">
            <label htmlFor="it-unit">{t("mk.unit")}</label>
            <select id="it-unit" value={unit} onChange={(e) => setUnit(e.target.value)}>
              <option value="">{t("mk.unit.none")}</option>
              {[...new Set([...(profile.units || []), ...(unit ? [unit] : [])])].map((u) => <option key={u} value={u}>{t(`mk.unit.${u}`)}</option>)}
            </select>
          </div>
        )}
        <div className="field span-2">
          <label htmlFor="it-cat">{t("mk.category")}</label>
          <select id="it-cat" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
            <option value="">{t("mk.noCategory")}</option>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div className="field span-2">
          <label htmlFor="it-desc">{t("mk.description")}</label>
          <textarea id="it-desc" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
      </div>

      {!services && (
        <section className="mk-block">
          <h3>{t("mk.sizes")}</h3>
          <p className="mk-hint">{t("mk.sizesHint")}</p>
          {sizes.map((s, i) => (
            <div className="mk-row" key={s.id || `n${i}`}>
              <input value={s.name} onChange={(e) => setSizes(sizes.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} placeholder={t("mk.sizeName")} aria-label={t("mk.sizeName")} />
              <input type="number" step="0.01" min="0" value={s.price} onChange={(e) => setSizes(sizes.map((x, j) => (j === i ? { ...x, price: e.target.value } : x)))} placeholder="0.00" aria-label={t("mk.price")} className="mk-price" />
              <button type="button" className="btn-sm btn-sm-red" onClick={() => setSizes(sizes.filter((_, j) => j !== i))} aria-label={t("mk.delete")}>✕</button>
            </div>
          ))}
          <button type="button" className="btn-sm" onClick={() => setSizes([...sizes, { name: "", price: price || "" }])}>{t("mk.addSize")}</button>
        </section>
      )}

      {!services && (
        <section className="mk-block">
          <h3>{t("mk.options")}</h3>
          <p className="mk-hint">{t("mk.optionsHint")}</p>
          {groups.map((g, i) => (
            <div className="mk-group" key={g.id || `g${i}`}>
              <div className="mk-row">
                <input value={g.name} onChange={(e) => setGroup(i, { name: e.target.value })} placeholder={t("mk.groupName")} aria-label={t("mk.groupName")} />
                <label className="mk-check"><input type="checkbox" checked={g.required} onChange={(e) => setGroup(i, { required: e.target.checked })} /> {t("mk.required")}</label>
                <label className="mk-check">{t("mk.maxChoices")} <input type="number" min="1" max="20" value={g.max_select} onChange={(e) => setGroup(i, { max_select: e.target.value })} className="mk-num" /></label>
                <button type="button" className="btn-sm btn-sm-red" onClick={() => setGroups(groups.filter((_, j) => j !== i))} aria-label={t("mk.delete")}>✕</button>
              </div>
              {g.modifiers.map((m, k) => (
                <div className="mk-row mk-choice" key={m.id || `m${k}`}>
                  <input value={m.name} onChange={(e) => setChoice(i, k, { name: e.target.value })} placeholder={t("mk.choice")} aria-label={t("mk.choice")} />
                  <input type="number" step="0.01" min="0" value={m.price_delta} onChange={(e) => setChoice(i, k, { price_delta: e.target.value })} placeholder={t("mk.extraPrice")} aria-label={t("mk.extraPrice")} className="mk-price" />
                  <button type="button" className="btn-sm btn-sm-red" onClick={() => setGroup(i, { modifiers: g.modifiers.filter((_, j) => j !== k) })} aria-label={t("mk.delete")}>✕</button>
                </div>
              ))}
              <button type="button" className="btn-sm" onClick={() => setGroup(i, { modifiers: [...g.modifiers, { name: "", price_delta: "" }] })}>{t("mk.addChoice")}</button>
            </div>
          ))}
          <div className="mk-row">
            <button type="button" className="btn-sm" onClick={() => addGroup()}>{t("mk.addGroup")}</button>
            {suggestions.length > 0 && <span className="mk-hint">{t("mk.quickAdd")}</span>}
            {suggestions.map((s) => <button type="button" key={s} className="btn-sm" onClick={() => addGroup(s)}>+ {s}</button>)}
          </div>
        </section>
      )}

      <section className="mk-block">
        <h3>{t("mk.offer")}</h3>
        <p className="mk-hint">{t("mk.offerHint")}</p>
        <div className="mk-row">
          <label className="mk-check">{t("mk.offerPercent")} <input type="number" min="1" max="90" value={offerPercent} onChange={(e) => setOfferPercent(e.target.value)} className="mk-num" /></label>
          <label className="mk-check">{t("mk.offerFrom")} <input type="date" value={offerStartsOn} min={item ? undefined : today()} onChange={(e) => setOfferStartsOn(e.target.value)} /></label>
          <label className="mk-check">{t("mk.offerTo")} <input type="date" value={offerEndsOn} min={offerStartsOn || undefined} onChange={(e) => setOfferEndsOn(e.target.value)} /></label>
        </div>
      </section>
    </Modal>
  );
}

function ImportMenuModal({ api, toast, onClose, onDone }) {
  const { t } = useLang();
  const [items, setItems] = useState(null);
  const [busy, setBusy] = useState(false);

  async function upload(file) {
    if (!file) return;
    const form = new FormData();
    form.append("file", file);
    setBusy(true);
    try {
      const data = await api("POST", "/menu/import/preview", form);
      setItems(data.items || []);
    } catch (e) { toast(e.message, "error"); } finally { setBusy(false); }
  }

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
          <input type="file" accept=".xlsx,.csv,.jpg,.jpeg,.png,.webp" disabled={busy} aria-label={t("mk.import")}
            onChange={(e) => upload(e.target.files?.[0])} />
          {busy && <div className="loader" style={{ marginTop: 12 }}>{t("mk.imp.reading")}</div>}
        </>
      ) : (
        <>
          <p style={{ fontSize: 13, marginBottom: 10 }}>{items.length} {t("mk.imp.found")}</p>
          <div className="table-scroll" style={{ maxHeight: "50vh", overflowY: "auto" }}>
            <table className="data-table stack-on-phone">
              <thead><tr><th>{t("mk.category")}</th><th>{t("mk.name")}</th><th>{t("mk.price")}</th><th></th></tr></thead>
              <tbody>
                {items.map((it, i) => (
                  <tr key={i}>
                    <td data-label={t("mk.category")}>{it.category}</td>
                    <td data-label={t("mk.name")}>{it.name}</td>
                    <td data-label={t("mk.price")}>{it.price}</td>
                    <td className="stack-actions"><button className="btn-sm" onClick={() => setItems(items.filter((_, j) => j !== i))} aria-label={t("mk.delete")}>✕</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="modal-actions">
            <button className="btn-sm" onClick={() => setItems(null)} disabled={busy}>{t("mk.cancel")}</button>
            <button className="btn-primary" onClick={confirm} disabled={busy || items.length === 0}>
              {busy ? t("db.loading") : `${t("mk.import")} (${items.length})`}
            </button>
          </div>
        </>
      )}
    </Modal>
  );
}
