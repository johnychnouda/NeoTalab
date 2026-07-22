/**
 * NeoTalab API — Main Server
 * AI WhatsApp Commerce OS
 */
import "dotenv/config";
import express from "express";
import helmet from "helmet";
import cors from "cors";

import { logger } from "./utils/logger.js";
import { globalLimiter, authLimiter, webhookLimiter } from "./middleware/rateLimiter.js";
import { errorHandler, notFoundHandler } from "./middleware/errorHandler.js";

// Routes
import authRoutes     from "./routes/auth.js";
import ownerRoutes    from "./routes/owner.js";
import menuRoutes     from "./routes/menu.js";
import ordersRoutes   from "./routes/orders.js";
import driversRoutes  from "./routes/drivers.js";
import customersRoutes from "./routes/customers.js";
import settingsRoutes from "./routes/settings.js";
import analyticsRoutes from "./routes/analytics.js";
import trackingRoutes from "./routes/tracking.js";
import webhookRoutes  from "./routes/webhooks.js";
import { startRenewalReminderJob } from "./jobs/renewalReminders.js";

const app = express();

// ── Security ─────────────────────────────────────────────────
app.use(helmet());
app.use(cors({
  origin: process.env.CORS_ORIGIN || "*",
  methods: ["GET","POST","PUT","PATCH","DELETE","OPTIONS"],
  allowedHeaders: ["Authorization","Content-Type"],
}));
app.set("trust proxy", 1);
app.use(globalLimiter);

// ── Body parsing ─────────────────────────────────────────────
app.use(express.json({ limit: "1mb" }));

// ── Request logging ──────────────────────────────────────────
app.use((req, _res, next) => {
  logger.debug("→", { method: req.method, path: req.path });
  next();
});

// ── Health ───────────────────────────────────────────────────
app.get("/health", (_req, res) => {
  res.json({
    ok: true,
    product: "NeoTalab API",
    version: "1.0.0",
    env: process.env.NODE_ENV || "development",
    ts: new Date().toISOString(),
  });
});

// ── Homepage ─────────────────────────────────────────────────
app.get("/", (_req, res) => {
  res.type("html").send(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NeoTalab API</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #0a0a0a; color: #e5e5e5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .card { background: #111; border: 1px solid #222; border-radius: 16px; padding: 40px; max-width: 520px; width: 100%; }
    h1 { font-size: 28px; font-weight: 800; color: #fff; margin-bottom: 6px; }
    .tag { display: inline-block; background: #128c7e22; color: #25d366; font-size: 12px; padding: 4px 10px; border-radius: 20px; margin-bottom: 24px; border: 1px solid #128c7e44; }
    h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #666; margin: 24px 0 12px; }
    a { display: block; color: #25d366; text-decoration: none; padding: 10px 14px; border-radius: 8px; background: #25d36612; border: 1px solid #25d36622; margin-bottom: 8px; font-size: 14px; transition: background 0.15s; }
    a:hover { background: #25d36622; }
    code { font-family: monospace; color: #888; font-size: 12px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>NeoTalab</h1>
    <span class="tag">AI WhatsApp Commerce OS · v1.0</span>
    <h2>Core</h2>
    <a href="/health">/health — API status</a>
    <a href="/api/analytics/dashboard">/api/analytics/dashboard — analytics (auth required)</a>
    <h2>Auth</h2>
    <a href="#"><code>POST /auth/owner/login</code></a>
    <a href="#"><code>POST /auth/merchant/login</code></a>
    <a href="#"><code>POST /auth/driver/login</code></a>
    <h2>Docs</h2>
    <a href="/api-docs">/api-docs — Full API reference</a>
  </div>
</body>
</html>`);
});

// ── Routes ───────────────────────────────────────────────────
app.use("/auth",           authLimiter, authRoutes);
app.use("/api/owner",      ownerRoutes);

// Merchant-scoped routes (can also be accessed by owner via /api/owner)
app.use("/api/menu",       menuRoutes);
app.use("/api/orders",     ordersRoutes);
app.use("/api/drivers",    driversRoutes);
app.use("/api/customers",  customersRoutes);
app.use("/api/settings",   settingsRoutes);
app.use("/api/analytics",  analyticsRoutes);
app.use("/api/tracking",   trackingRoutes);

// WhatsApp webhooks (no auth — verified by signature)
app.use("/webhooks/whatsapp", webhookLimiter, webhookRoutes);

// ── Error handling ───────────────────────────────────────────
app.use(notFoundHandler);
app.use(errorHandler);

// ── Start ────────────────────────────────────────────────────
const PORT = process.env.PORT || 8787;
app.listen(PORT, () => {
  logger.info(`NeoTalab API running`, {
    port: PORT,
    env: process.env.NODE_ENV || "development",
    url: `http://localhost:${PORT}`,
  });
  startRenewalReminderJob();
});

export default app;
