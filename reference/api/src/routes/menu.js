/**
 * Menu routes — categories, products, modifiers
 * Features: #8, #9, #11, #12
 */
import { Router } from "express";
import { v4 as uuidv4 } from "uuid";
import { query } from "../db.js";
import { requireAuth, requireMerchant } from "../middleware/auth.js";
import { ok, created, err, notFound } from "../utils/response.js";

const router = Router();
router.use(requireAuth, requireMerchant);

function getMerchantId(req) {
  return req.params.merchantId || req.auth.merchantId;
}

// ── CATEGORIES ───────────────────────────────────────────────

// GET /menu/categories
router.get("/categories", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT c.*, COUNT(p.id) as product_count
       FROM categories c
       LEFT JOIN products p ON p.category_id = c.id AND p.is_active = TRUE
       WHERE c.merchant_id = $1
       GROUP BY c.id
       ORDER BY c.sort_order, c.name`,
      [merchantId]
    );
    ok(res, { categories: rows });
  } catch (e) { next(e); }
});

// POST /menu/categories
router.post("/categories", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { name, nameAr, nameFr, sortOrder = 0 } = req.body;
    if (!name) return err(res, "Category name required");

    const { rows } = await query(
      `INSERT INTO categories (merchant_id, name, name_ar, name_fr, sort_order)
       VALUES ($1,$2,$3,$4,$5) RETURNING *`,
      [merchantId, name, nameAr, nameFr, sortOrder]
    );
    created(res, { category: rows[0] });
  } catch (e) { next(e); }
});

// PATCH /menu/categories/:id
router.patch("/categories/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { name, nameAr, nameFr, sortOrder, isActive } = req.body;

    const { rows } = await query(
      `UPDATE categories SET
         name = COALESCE($1, name),
         name_ar = COALESCE($2, name_ar),
         name_fr = COALESCE($3, name_fr),
         sort_order = COALESCE($4, sort_order),
         is_active = COALESCE($5, is_active)
       WHERE id = $6 AND merchant_id = $7 RETURNING *`,
      [name, nameAr, nameFr, sortOrder, isActive, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Category not found");
    ok(res, { category: rows[0] });
  } catch (e) { next(e); }
});

// DELETE /menu/categories/:id
router.delete("/categories/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      "DELETE FROM categories WHERE id = $1 AND merchant_id = $2 RETURNING id",
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Category not found");
    ok(res, { message: "Category deleted" });
  } catch (e) { next(e); }
});

// ── PRODUCTS ─────────────────────────────────────────────────

// GET /menu/products
router.get("/products", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { categoryId, includeInactive } = req.query;

    let sql = `
      SELECT p.*, c.name as category_name,
        (SELECT json_agg(mg ORDER BY mg.sort_order) FROM (
          SELECT mg.*, json_agg(mo ORDER BY mo.sort_order) as options
          FROM modifier_groups mg
          JOIN modifiers mo ON mo.group_id = mg.id
          WHERE mg.product_id = p.id
          GROUP BY mg.id
        ) mg) as modifier_groups
      FROM products p
      LEFT JOIN categories c ON c.id = p.category_id
      WHERE p.merchant_id = $1
    `;
    const params = [merchantId];

    if (!includeInactive) {
      sql += " AND p.is_active = TRUE";
    }
    if (categoryId) {
      params.push(categoryId);
      sql += ` AND p.category_id = $${params.length}`;
    }
    sql += " ORDER BY p.sort_order, p.name";

    const { rows } = await query(sql, params);
    ok(res, { products: rows });
  } catch (e) { next(e); }
});

// GET /menu/products/:id
router.get("/products/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT p.*, c.name as category_name FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
       WHERE p.id = $1 AND p.merchant_id = $2`,
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Product not found");

    const { rows: groups } = await query(
      `SELECT mg.*, json_agg(mo ORDER BY mo.sort_order) as options
       FROM modifier_groups mg
       JOIN modifiers mo ON mo.group_id = mg.id
       WHERE mg.product_id = $1
       GROUP BY mg.id
       ORDER BY mg.sort_order`,
      [req.params.id]
    );

    ok(res, { product: { ...rows[0], modifierGroups: groups } });
  } catch (e) { next(e); }
});

// POST /menu/products
router.post("/products", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const {
      name, nameAr, nameFr, description, descriptionAr,
      price, categoryId, imageUrl, prepTimeMins = 15, sortOrder = 0,
    } = req.body;

    if (!name || !price) return err(res, "Product name and price required");

    const { rows } = await query(
      `INSERT INTO products (merchant_id, category_id, name, name_ar, name_fr,
         description, description_ar, price, image_url, prep_time_mins, sort_order)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11) RETURNING *`,
      [merchantId, categoryId, name, nameAr, nameFr,
       description, descriptionAr, price, imageUrl, prepTimeMins, sortOrder]
    );
    created(res, { product: rows[0] });
  } catch (e) { next(e); }
});

// PATCH /menu/products/:id
router.patch("/products/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const fields = ["name","name_ar","name_fr","description","description_ar",
                    "price","category_id","image_url","is_active","is_sold_out",
                    "prep_time_mins","sort_order"];
    const updates = [];
    const values = [];

    for (const f of fields) {
      const camel = f.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
      const val = req.body[camel] ?? req.body[f];
      if (val !== undefined) {
        values.push(val);
        updates.push(`${f} = $${values.length}`);
      }
    }

    if (!updates.length) return err(res, "No valid fields to update");
    values.push(req.params.id, merchantId);

    const { rows } = await query(
      `UPDATE products SET ${updates.join(", ")} WHERE id = $${values.length - 1} AND merchant_id = $${values.length} RETURNING *`,
      values
    );
    if (!rows[0]) return notFound(res, "Product not found");
    ok(res, { product: rows[0] });
  } catch (e) { next(e); }
});

// DELETE /menu/products/:id
router.delete("/products/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      "UPDATE products SET is_active = FALSE WHERE id = $1 AND merchant_id = $2 RETURNING id",
      [req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Product not found");
    ok(res, { message: "Product deactivated" });
  } catch (e) { next(e); }
});

// ── MODIFIER GROUPS ──────────────────────────────────────────

// POST /menu/products/:productId/modifier-groups
router.post("/products/:productId/modifier-groups", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { name, nameAr, nameFr, required = false, minSelect = 0, maxSelect = 1 } = req.body;

    // Verify product belongs to merchant
    const prod = await query(
      "SELECT id FROM products WHERE id = $1 AND merchant_id = $2",
      [req.params.productId, merchantId]
    );
    if (!prod.rows[0]) return notFound(res, "Product not found");

    const { rows } = await query(
      `INSERT INTO modifier_groups (product_id, name, name_ar, name_fr, required, min_select, max_select)
       VALUES ($1,$2,$3,$4,$5,$6,$7) RETURNING *`,
      [req.params.productId, name, nameAr, nameFr, required, minSelect, maxSelect]
    );
    created(res, { modifierGroup: rows[0] });
  } catch (e) { next(e); }
});

// POST /menu/modifier-groups/:groupId/modifiers
router.post("/modifier-groups/:groupId/modifiers", async (req, res, next) => {
  try {
    const { name, nameAr, nameFr, priceDelta = 0 } = req.body;
    if (!name) return err(res, "Modifier name required");

    const { rows } = await query(
      `INSERT INTO modifiers (group_id, name, name_ar, name_fr, price_delta)
       VALUES ($1,$2,$3,$4,$5) RETURNING *`,
      [req.params.groupId, name, nameAr, nameFr, priceDelta]
    );
    created(res, { modifier: rows[0] });
  } catch (e) { next(e); }
});

// DELETE /menu/modifier-groups/:groupId
router.delete("/modifier-groups/:groupId", async (req, res, next) => {
  try {
    await query("DELETE FROM modifier_groups WHERE id = $1", [req.params.groupId]);
    ok(res, { message: "Modifier group deleted" });
  } catch (e) { next(e); }
});

export default router;
