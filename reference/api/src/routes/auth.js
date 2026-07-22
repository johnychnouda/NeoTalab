import { Router } from "express";
import bcrypt from "bcrypt";
import { query } from "../db.js";
import { signToken } from "../middleware/auth.js";
import { ok, err, unauthorized } from "../utils/response.js";

const router = Router();

// POST /auth/owner/login
router.post("/owner/login", async (req, res, next) => {
  try {
    const { email, password } = req.body;
    if (!email || !password) return err(res, "Email and password required");

    const { rows } = await query(
      "SELECT id, name, email, password_hash FROM owners WHERE email = $1",
      [email.toLowerCase().trim()]
    );

    const owner = rows[0];
    if (!owner) return unauthorized(res, "Invalid credentials");

    const valid = await bcrypt.compare(password, owner.password_hash);
    if (!valid) return unauthorized(res, "Invalid credentials");

    const token = signToken({ id: owner.id, role: "owner", name: owner.name });

    ok(res, {
      token,
      user: { id: owner.id, name: owner.name, email: owner.email, role: "owner" },
    });
  } catch (e) { next(e); }
});

// POST /auth/merchant/login
router.post("/merchant/login", async (req, res, next) => {
  try {
    const { email, password } = req.body;
    if (!email || !password) return err(res, "Email and password required");

    const { rows } = await query(
      `SELECT id, shop_name, email, password_hash, status
       FROM merchants WHERE email = $1`,
      [email.toLowerCase().trim()]
    );

    const merchant = rows[0];
    if (!merchant) return unauthorized(res, "Invalid credentials");
    if (merchant.status === "suspended") return unauthorized(res, "Account suspended. Contact support.");
    if (merchant.status === "pending") return unauthorized(res, "Account pending approval.");
    if (merchant.status === "cancelled") return unauthorized(res, "Account cancelled.");

    const valid = await bcrypt.compare(password, merchant.password_hash);
    if (!valid) return unauthorized(res, "Invalid credentials");

    const token = signToken({
      id: merchant.id,
      role: "merchant",
      merchantId: merchant.id,
      name: merchant.shop_name,
    });

    ok(res, {
      token,
      user: {
        id: merchant.id,
        shopName: merchant.shop_name,
        email: merchant.email,
        role: "merchant",
      },
    });
  } catch (e) { next(e); }
});

// POST /auth/driver/login
router.post("/driver/login", async (req, res, next) => {
  try {
    const { phone, password } = req.body;
    if (!phone || !password) return err(res, "Phone and password required");

    const { rows } = await query(
      `SELECT d.id, d.name, d.phone, d.password_hash, d.merchant_id, d.is_active, m.shop_name
       FROM drivers d JOIN merchants m ON d.merchant_id = m.id
       WHERE d.phone = $1`,
      [phone.trim()]
    );

    const driver = rows[0];
    if (!driver) return unauthorized(res, "Invalid credentials");
    if (!driver.is_active) return unauthorized(res, "Account inactive");

    const valid = await bcrypt.compare(password, driver.password_hash);
    if (!valid) return unauthorized(res, "Invalid credentials");

    const token = signToken({
      id: driver.id,
      role: "driver",
      merchantId: driver.merchant_id,
      name: driver.name,
    });

    ok(res, {
      token,
      user: {
        id: driver.id,
        name: driver.name,
        phone: driver.phone,
        shopName: driver.shop_name,
        role: "driver",
      },
    });
  } catch (e) { next(e); }
});

// GET /auth/me
router.get("/me", async (req, res, next) => {
  try {
    const header = req.headers.authorization || "";
    const token = header.startsWith("Bearer ") ? header.slice(7) : null;
    if (!token) return unauthorized(res);

    const { verifyToken } = await import("../middleware/auth.js");
    const payload = verifyToken(token);
    ok(res, { user: payload });
  } catch {
    unauthorized(res);
  }
});

export default router;
