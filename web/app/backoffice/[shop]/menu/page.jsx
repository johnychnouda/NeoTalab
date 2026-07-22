"use client";

import { useCallback, useEffect, useState } from "react";
import { useMerchant } from "../../layout";
import { useLang } from "@/lib/i18n";
import { Modal, ConfirmModal, useToast } from "@/components/ui";

export default function MenuPage() {
  const { api } = useMerchant();
  const { t } = useLang();
  const toast = useToast();

  const [categories, setCategories] = useState(null);
  const [products, setProducts] = useState([]);
  const [error, setError] = useState("");
  const [modal, setModal] = useState(null);

  const load = useCallback(async () => {
    try {
      const [{ categories: cats }, { products: prods }] = await Promise.all([
        api("GET", "/api/menu/categories"),
        api("GET", "/api/menu/products?includeInactive=true"),
      ]);
      setCategories(cats);
      setProducts(prods);
    } catch (e) { setError(e.message); }
  }, [api]);

  useEffect(() => { load(); }, [load]);

  async function run(fn) {
    try { await fn(); setModal(null); load(); }
    catch (e) { toast(e.message, "error"); }
  }

  const toggleSoldOut = (p) => run(() => api("PATCH", `/api/menu/products/${p.id}`, { isSoldOut: !p.is_sold_out }));

  return (
    <div>
      <div className="page-header">
        <h1>{t("menu.title")}</h1>
        <div style={{ display: "flex", gap: 10 }}>
          <button className="btn-sm" onClick={() => setModal({ type: "addCategory" })}>+ {t("menu.addCat")}</button>
          <button className="btn-primary" onClick={() => setModal({ type: "addProduct" })}>+ {t("menu.addProduct")}</button>
        </div>
      </div>

      {error && <div style={{ color: "var(--red)" }}>{error}</div>}
      {categories === null && !error && <div className="loader">Loading…</div>}
      {categories && categories.length === 0 && (
        <div className="empty-state"><div className="empty-icon">🍽️</div><div>Add categories and products to build your menu</div></div>
      )}

      <div className="menu-list">
        {categories && categories.map((cat) => {
          const prods = products.filter((p) => p.category_id === cat.id || p.category_name === cat.name);
          return (
            <div className="menu-category" key={cat.id}>
              <div className="menu-cat-header">
                <span className="menu-cat-name">{cat.name}</span>
                {cat.name_ar && <span style={{ color: "var(--text-muted)", fontSize: 12 }} dir="rtl">{cat.name_ar}</span>}
                <span className="menu-cat-count">{prods.length} items</span>
                <button className="btn-sm" onClick={() => setModal({ type: "addProduct", categoryId: cat.id })}>+ Product</button>
                <button className="btn-sm" onClick={() => setModal({ type: "editCategory", cat })}>Edit</button>
                <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "deleteCategory", cat })}>Delete</button>
              </div>
              <div>
                {prods.length === 0 && <div style={{ padding: 12, color: "var(--text-muted)", fontSize: 13 }}>No products yet</div>}
                {prods.map((p) => (
                  <div key={p.id} className={`product-row ${!p.is_active ? "product-inactive" : ""}`}>
                    <div className="product-info">
                      <span className="product-name">{p.name}</span>
                      {p.name_ar && <span className="product-name-ar" dir="rtl">{p.name_ar}</span>}
                    </div>
                    <div className="product-meta">
                      <span className="product-price">${parseFloat(p.price).toFixed(2)}</span>
                      {p.prep_time_mins ? <span style={{ fontSize: 11, color: "var(--text-muted)" }}>⏱ {p.prep_time_mins}m</span> : null}
                      {p.is_sold_out && <span className="pill pill-suspended" style={{ fontSize: 10 }}>Sold out</span>}
                      {!p.is_active && <span className="pill pill-pending" style={{ fontSize: 10 }}>Hidden</span>}
                    </div>
                    <div className="product-actions">
                      <button className="btn-sm" onClick={() => toggleSoldOut(p)}>{p.is_sold_out ? "✅ In Stock" : "🚫 Sold Out"}</button>
                      <button className="btn-sm" onClick={() => setModal({ type: "editProduct", product: p })}>Edit</button>
                      <button className="btn-sm btn-sm-red" onClick={() => setModal({ type: "deleteProduct", product: p })}>✕</button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          );
        })}
      </div>

      {modal?.type === "addCategory" && (
        <CategoryModal title="Add Category" onClose={() => setModal(null)}
          onSubmit={(name, nameAr) => {
            if (!name) { toast("Category name is required", "error"); return; }
            run(() => api("POST", "/api/menu/categories", { name, nameAr }));
          }} />
      )}
      {modal?.type === "editCategory" && (
        <CategoryModal title="Edit Category" initial={modal.cat} onClose={() => setModal(null)}
          onSubmit={(name, nameAr) => run(() => api("PATCH", `/api/menu/categories/${modal.cat.id}`, { name, nameAr }))} />
      )}
      {modal?.type === "deleteCategory" && (
        <ConfirmModal title="Delete Category"
          message={`Delete <strong>${modal.cat.name}</strong>? Products in it will be uncategorized.`}
          danger onClose={() => setModal(null)}
          onConfirm={() => run(() => api("DELETE", `/api/menu/categories/${modal.cat.id}`))} />
      )}
      {modal?.type === "addProduct" && (
        <ProductModal
          title="Add Product"
          categories={categories || []}
          defaultCategoryId={modal.categoryId}
          onClose={() => setModal(null)}
          onSubmit={(vals) => {
            if (!vals.name || isNaN(vals.price)) { toast("Name and price are required", "error"); return; }
            run(() => api("POST", "/api/menu/products", {
              name: vals.name,
              nameAr: vals.nameAr,
              description: vals.description || undefined,
              price: vals.price,
              prepTimeMins: vals.prep || 15,
              categoryId: vals.categoryId || undefined,
            }));
          }}
        />
      )}
      {modal?.type === "editProduct" && (
        <ProductModal
          title="Edit Product"
          initial={modal.product}
          categories={categories || []}
          showActive
          onClose={() => setModal(null)}
          onSubmit={(vals) => {
            const body = { isActive: vals.isActive };
            if (vals.name) body.name = vals.name;
            if (vals.nameAr) body.nameAr = vals.nameAr;
            if (vals.description !== undefined) body.description = vals.description;
            if (vals.categoryId !== undefined) body.categoryId = vals.categoryId || null;
            if (!isNaN(vals.price)) body.price = vals.price;
            if (!isNaN(vals.prep)) body.prepTimeMins = vals.prep;
            run(() => api("PATCH", `/api/menu/products/${modal.product.id}`, body));
          }}
        />
      )}
      {modal?.type === "deleteProduct" && (
        <ConfirmModal title="Remove Product"
          message={`Remove <strong>${modal.product.name}</strong> from the menu?`}
          danger onClose={() => setModal(null)}
          onConfirm={() => run(() => api("DELETE", `/api/menu/products/${modal.product.id}`))} />
      )}
    </div>
  );
}

function CategoryModal({ title, initial, onClose, onSubmit }) {
  const [name, setName] = useState(initial?.name || "");
  const [nameAr, setNameAr] = useState(initial?.name_ar || "");
  return (
    <Modal
      title={title}
      onClose={onClose}
      compact
      footer={(
        <div className="modal-footer-actions">
          <button type="button" className="btn-sm" onClick={onClose}>Cancel</button>
          <button type="button" className="btn-primary" onClick={() => onSubmit(name.trim(), nameAr)}>{initial ? "Save" : "Add"}</button>
        </div>
      )}
    >
      <div className="modal-form-grid">
        <div className="field"><label>Name (EN)</label><input value={name} onChange={(e) => setName(e.target.value)} placeholder="Sandwiches" /></div>
        <div className="field"><label>Name (AR)</label><input value={nameAr} onChange={(e) => setNameAr(e.target.value)} dir="rtl" placeholder="سندويشات" /></div>
      </div>
    </Modal>
  );
}

function ProductModal({ title, initial, categories = [], defaultCategoryId, showActive, onClose, onSubmit }) {
  const [name, setName] = useState(initial?.name || "");
  const [nameAr, setNameAr] = useState(initial?.name_ar || "");
  const [description, setDescription] = useState(initial?.description || "");
  const [categoryId, setCategoryId] = useState(initial?.category_id || defaultCategoryId || "");
  const [price, setPrice] = useState(initial?.price ?? "");
  const [prep, setPrep] = useState(initial?.prep_time_mins ?? 15);
  const [isActive, setIsActive] = useState(initial?.is_active !== false);

  return (
    <Modal
      title={title}
      onClose={onClose}
      compact
      footer={(
        <div className="modal-footer-actions">
          <button type="button" className="btn-sm" onClick={onClose}>Cancel</button>
          <button type="button" className="btn-primary" onClick={() => onSubmit({
            name: name.trim(),
            nameAr: nameAr.trim(),
            description: description.trim(),
            categoryId: categoryId || "",
            price: parseFloat(price),
            prep: parseInt(prep, 10),
            isActive,
          })}>{initial ? "Save Changes" : "Add Product"}</button>
        </div>
      )}
    >
      <div className="modal-form-grid">
        {categories.length > 0 && (
          <div className="field span-2">
            <label>Category</label>
            <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
              <option value="">Uncategorized</option>
              {categories.map((cat) => (
                <option key={cat.id} value={cat.id}>{cat.name}</option>
              ))}
            </select>
          </div>
        )}
        <div className="field">
          <label>Name (EN)</label>
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Shawarma" autoFocus />
        </div>
        <div className="field">
          <label>Name (AR)</label>
          <input value={nameAr} onChange={(e) => setNameAr(e.target.value)} dir="rtl" placeholder="شاورما" />
        </div>
        <div className="field">
          <label>Price ($)</label>
          <input type="number" step="0.01" min="0" value={price} onChange={(e) => setPrice(e.target.value)} placeholder="6.00" />
        </div>
        <div className="field">
          <label>Prep Time (mins)</label>
          <input type="number" min="0" max="1440" value={prep} onChange={(e) => setPrep(e.target.value)} placeholder="15" />
        </div>
        <div className="field span-2">
          <label>Description <span style={{ fontWeight: 500, textTransform: "none", letterSpacing: 0 }}>(optional)</span></label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Ingredients, size, or notes shown to customers…"
            rows={2}
          />
        </div>
        {showActive && (
          <div className="field checkbox-field span-2">
            <input type="checkbox" id="p-active" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
            <label htmlFor="p-active">Visible in menu</label>
          </div>
        )}
      </div>
    </Modal>
  );
}
