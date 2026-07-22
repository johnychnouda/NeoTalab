/**
 * Merchant settings — hours, zones, shop config
 * Features: #2, #25, #27, #35, #64, #78
 */
import { Router } from "express";
import { query } from "../db.js";
import { requireAuth, requireMerchant } from "../middleware/auth.js";
import { ok, created, err, notFound } from "../utils/response.js";

const router = Router();
router.use(requireAuth, requireMerchant);

function getMerchantId(req) {
  return req.params.merchantId || req.auth.merchantId;
}

// ── SHOP PROFILE ─────────────────────────────────────────────

// GET /settings/profile
router.get("/profile", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT id, shop_name, shop_name_ar, shop_name_fr, logo_url, description,
              email, whatsapp_number, ops_whatsapp, address, mode,
              auto_accept_orders, default_locale, currency,
              wish_number, wish_auto_confirm, wish_timeout_mins,
              plan, status, monthly_fee, subscription_ends, created_at
       FROM merchants WHERE id = $1`,
      [merchantId]
    );
    if (!rows[0]) return notFound(res, "Merchant not found");
    ok(res, { profile: rows[0] });
  } catch (e) { next(e); }
});

// PATCH /settings/profile
router.patch("/profile", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const allowed = [
      "shop_name","shop_name_ar","shop_name_fr","logo_url","description",
      "ops_whatsapp","address","default_locale",
      "wish_number","wish_auto_confirm","wish_timeout_mins",
    ];
    const updates = [];
    const values = [];

    for (const key of allowed) {
      const camel = key.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
      const val = req.body[camel] ?? req.body[key];
      if (val !== undefined) {
        values.push(val);
        updates.push(`${key} = $${values.length}`);
      }
    }

    if (!updates.length) return err(res, "No valid fields to update");
    values.push(merchantId);

    const { rows } = await query(
      `UPDATE merchants SET ${updates.join(", ")} WHERE id = $${values.length} RETURNING *`,
      values
    );
    const profile = { ...rows[0] };
    delete profile.password_hash;
    delete profile.wa_access_token;
    ok(res, { profile });
  } catch (e) { next(e); }
});

// PATCH /settings/mode  (#5, #6)
router.patch("/mode", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { mode } = req.body;
    const valid = ["auto", "busy", "manual", "closed"];
    if (!valid.includes(mode)) return err(res, `Mode must be one of: ${valid.join(", ")}`);

    await query("UPDATE merchants SET mode = $1 WHERE id = $2", [mode, merchantId]);
    ok(res, { mode, message: `Shop mode set to ${mode}` });
  } catch (e) { next(e); }
});

// PATCH /settings/whatsapp — connect WA Business API (#72, #76)
router.patch("/whatsapp", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { waPhoneId, waAccessToken, waWebhookSecret } = req.body;

    await query(
      `UPDATE merchants SET wa_phone_id = COALESCE($1, wa_phone_id),
         wa_access_token = COALESCE($2, wa_access_token),
         wa_webhook_secret = COALESCE($3, wa_webhook_secret)
       WHERE id = $4`,
      [waPhoneId, waAccessToken, waWebhookSecret, merchantId]
    );
    ok(res, { message: "WhatsApp settings updated" });
  } catch (e) { next(e); }
});

// ── SHOP HOURS (#35) ─────────────────────────────────────────

// GET /settings/hours
router.get("/hours", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT * FROM shop_hours WHERE merchant_id = $1 ORDER BY day_of_week`,
      [merchantId]
    );
    ok(res, { hours: rows });
  } catch (e) { next(e); }
});

// PUT /settings/hours — replace all hours
router.put("/hours", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { hours } = req.body; // [{dayOfWeek, opensAt, closesAt, isClosed}]

    if (!Array.isArray(hours) || hours.length !== 7) {
      return err(res, "Must provide hours for all 7 days");
    }

    await query("DELETE FROM shop_hours WHERE merchant_id = $1", [merchantId]);

    for (const h of hours) {
      await query(
        `INSERT INTO shop_hours (merchant_id, day_of_week, opens_at, closes_at, is_closed)
         VALUES ($1,$2,$3,$4,$5)`,
        [merchantId, h.dayOfWeek, h.opensAt, h.closesAt, h.isClosed || false]
      );
    }

    ok(res, { message: "Hours updated" });
  } catch (e) { next(e); }
});

// ── DELIVERY ZONES (#25, #27) ────────────────────────────────

// GET /settings/zones
router.get("/zones", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { rows } = await query(
      `SELECT * FROM delivery_zones WHERE merchant_id = $1 AND is_active = TRUE
       ORDER BY sort_order, name`,
      [merchantId]
    );
    ok(res, { zones: rows });
  } catch (e) { next(e); }
});

// POST /settings/zones
router.post("/zones", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { name, zoneType = "radius", coordinates, deliveryFee, minimumOrder, sortOrder = 0 } = req.body;
    if (!name || deliveryFee === undefined) return err(res, "name and deliveryFee required");

    const { rows } = await query(
      `INSERT INTO delivery_zones (merchant_id, name, zone_type, coordinates, delivery_fee, minimum_order, sort_order)
       VALUES ($1,$2,$3,$4,$5,$6,$7) RETURNING *`,
      [merchantId, name, zoneType, JSON.stringify(coordinates), deliveryFee, minimumOrder || 0, sortOrder]
    );
    created(res, { zone: rows[0] });
  } catch (e) { next(e); }
});

// PATCH /settings/zones/:id
router.patch("/zones/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    const { name, deliveryFee, minimumOrder, isActive, coordinates, sortOrder } = req.body;

    const { rows } = await query(
      `UPDATE delivery_zones SET
         name = COALESCE($1, name),
         delivery_fee = COALESCE($2, delivery_fee),
         minimum_order = COALESCE($3, minimum_order),
         is_active = COALESCE($4, is_active),
         coordinates = COALESCE($5, coordinates),
         sort_order = COALESCE($6, sort_order)
       WHERE id = $7 AND merchant_id = $8 RETURNING *`,
      [name, deliveryFee, minimumOrder, isActive,
       coordinates ? JSON.stringify(coordinates) : null,
       sortOrder, req.params.id, merchantId]
    );
    if (!rows[0]) return notFound(res, "Zone not found");
    ok(res, { zone: rows[0] });
  } catch (e) { next(e); }
});

// DELETE /settings/zones/:id
router.delete("/zones/:id", async (req, res, next) => {
  try {
    const merchantId = getMerchantId(req);
    await query(
      "UPDATE delivery_zones SET is_active = FALSE WHERE id = $1 AND merchant_id = $2",
      [req.params.id, merchantId]
    );
    ok(res, { message: "Zone removed" });
  } catch (e) { next(e); }
});

export default router;
