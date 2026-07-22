/** Full lifecycle #54 — IGNORE #55 simplified */
export const OrderStatus = {
  DRAFT: "draft",
  AWAITING_WISH: "awaiting_wish",
  PENDING_CONFIRM: "pending_confirm",
  CONFIRMED: "confirmed",
  PREPARING: "preparing",
  READY_FOR_PICKUP: "ready_for_pickup",
  AWAITING_DRIVER: "awaiting_driver",
  DRIVER_ASSIGNED: "driver_assigned",
  OUT_FOR_DELIVERY: "out_for_delivery",
  DRIVER_NEARBY: "driver_nearby",
  DRIVER_ARRIVED: "driver_arrived",
  DELIVERED: "delivered",
  CANCELLED: "cancelled",
};

export const DRIVER_STATUS = {
  OFFLINE: "offline",
  AVAILABLE: "available",
  BUSY: "busy",
  PAUSED: "paused",
  ON_BREAK: "on_break",
};
