import jwt from "jsonwebtoken";
import { query } from "../db.js";
import { unauthorized, forbidden } from "../utils/response.js";

const JWT_SECRET = process.env.JWT_SECRET || "dev-secret-change-in-production";

async function ownerTokenStillValid(ownerId, iatSeconds) {
  try {
    const { rows } = await query(
      "SELECT tokens_valid_after FROM owners WHERE id = $1",
      [ownerId]
    );
    if (!rows[0]?.tokens_valid_after) return true;
    const validAfter = new Date(rows[0].tokens_valid_after).getTime();
    return iatSeconds * 1000 >= validAfter - 500;
  } catch {
    return true;
  }
}

export function signToken(payload) {
  return jwt.sign(payload, JWT_SECRET, {
    expiresIn: process.env.JWT_EXPIRES_IN || "7d",
  });
}

export function verifyToken(token) {
  return jwt.verify(token, JWT_SECRET);
}

// Middleware: require any valid JWT
export async function requireAuth(req, res, next) {
  const header = req.headers.authorization || "";
  const token = header.startsWith("Bearer ") ? header.slice(7) : null;

  if (!token) return unauthorized(res, "Missing authorization token");

  try {
    const payload = verifyToken(token);

    if (payload.role === "owner" && payload.iat) {
      const valid = await ownerTokenStillValid(payload.id, payload.iat);
      if (!valid) return unauthorized(res, "Session expired. Please sign in again.");
    }

    req.auth = payload; // { id, role, merchantId? }
    next();
  } catch {
    return unauthorized(res, "Invalid or expired token");
  }
}

// Middleware: owner only
export function requireOwner(req, res, next) {
  if (!req.auth || req.auth.role !== "owner") {
    return forbidden(res, "Owner access required");
  }
  next();
}

// Middleware: merchant (or owner impersonating)
export function requireMerchant(req, res, next) {
  if (!req.auth) return unauthorized(res);
  if (req.auth.role === "owner" || req.auth.role === "merchant") return next();
  return forbidden(res, "Merchant access required");
}

// Middleware: ensure merchant can only touch their own data
// Unless they are owner (full access)
export function requireMerchantScope(req, res, next) {
  if (!req.auth) return unauthorized(res);
  if (req.auth.role === "owner") return next(); // owner sees everything

  const merchantId = req.params.merchantId || req.auth.merchantId;
  if (req.auth.role === "merchant" && req.auth.merchantId !== merchantId) {
    return forbidden(res, "Access denied to this merchant");
  }
  next();
}
