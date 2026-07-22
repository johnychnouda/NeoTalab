import { logger } from "../utils/logger.js";

export function errorHandler(err, req, res, next) {
  logger.error("Unhandled error", {
    method: req.method,
    path: req.path,
    error: err.message,
    stack: process.env.NODE_ENV !== "production" ? err.stack : undefined,
  });

  if (res.headersSent) return next(err);

  const status = err.status || err.statusCode || 500;
  res.status(status).json({
    ok: false,
    error: process.env.NODE_ENV === "production" ? "Internal server error" : err.message,
  });
}

export function notFoundHandler(req, res) {
  res.status(404).json({ ok: false, error: `Route ${req.method} ${req.path} not found` });
}
