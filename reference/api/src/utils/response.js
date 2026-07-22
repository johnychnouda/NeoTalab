export const ok = (res, data, status = 200) => res.status(status).json({ ok: true, ...data });

export const created = (res, data) => ok(res, data, 201);

export const err = (res, message, status = 400, code = null) =>
  res.status(status).json({ ok: false, error: message, ...(code && { code }) });

export const notFound = (res, message = "Not found") => err(res, message, 404);

export const unauthorized = (res, message = "Unauthorized") => err(res, message, 401);

export const forbidden = (res, message = "Forbidden") => err(res, message, 403);

export const serverError = (res, message = "Internal server error") => err(res, message, 500);
