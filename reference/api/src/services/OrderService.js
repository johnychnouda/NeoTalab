import { v4 as uuidv4 } from "uuid";
import { OrderStatus } from "../enums/OrderStatus.js";
import { tenant, orders, customers } from "../data/seed.js";

let orderSeq = 42;
import { validateLineItems, calcLineTotal } from "./MenuService.js";
import { checkZone } from "./DeliveryZoneService.js";
import { assignAvailableDriver, releaseDriver } from "./DispatchService.js";
import { computeEtaMinutes } from "./TrackingService.js";

/** Core order flow #13 #54 #56 #57 */
export function createDraft(phone, lines, locale = "ar") {
  const errs = validateLineItems(lines);
  if (errs.length) return { ok: false, errors: errs, partial: true };
  let subtotal = lines.reduce((s, l) => s + calcLineTotal(l), 0);
  const ref = `ORD-2026-${String(++orderSeq).padStart(5, "0")}`;
  const order = {
    id: uuidv4(),
    reference: ref,
    customer_phone: phone,
    status: OrderStatus.DRAFT,
    lines,
    subtotal,
    delivery_fee: 0,
    total: subtotal,
    locale,
    fulfillment_type: null,
    payment_method: null,
    created_at: new Date().toISOString(),
  };
  orders.set(order.id, order);
  if (!customers.has(phone)) customers.set(phone, { phone, locale, addresses: [] });
  return { ok: true, order };
}

export function applyFulfillment(orderId, type, address = {}) {
  const o = orders.get(orderId);
  if (!o) return { ok: false };
  o.fulfillment_type = type;
  if (type === "delivery") {
    const z = checkZone(address.lat, address.lng, o.subtotal);
    if (!z.ok) return { ok: false, zone: z };
    o.delivery_fee = z.fee;
    o.delivery_building = address.building;
    o.delivery_floor = address.floor;
    o.delivery_additional = address.additional;
    o.delivery_lat = address.lat;
    o.delivery_lng = address.lng;
    o.total = o.subtotal + o.delivery_fee;
  } else {
    o.delivery_fee = 0;
    o.total = o.subtotal;
  }
  return { ok: true, order: o };
}

export function confirmOrder(orderId, paymentMethod, autoAccept = true) {
  const o = orders.get(orderId);
  if (!o) return { ok: false };
  o.payment_method = paymentMethod;
  if (paymentMethod === "wish") {
    o.status = OrderStatus.AWAITING_WISH;
    return { ok: true, order: o, awaiting_wish: true };
  }
  if (autoAccept || tenant.auto_accept_orders) {
    o.status = OrderStatus.CONFIRMED;
    o.payment_status = "unpaid";
    afterConfirm(o);
    return { ok: true, order: o, auto: true };
  }
  o.status = OrderStatus.PENDING_CONFIRM;
  return { ok: true, order: o, auto: false };
}

export function wishPaymentReceived(orderId, amount) {
  const o = orders.get(orderId);
  if (!o) return { ok: false };
  if (Math.abs(amount - o.total) > (tenant.wish_amount_tolerance || 1000)) {
    return { ok: false, reason: "wrong_amount", expected: o.total, received: amount };
  }
  o.status = OrderStatus.CONFIRMED;
  o.payment_status = "paid";
  o.wish_detected = true;
  afterConfirm(o);
  return { ok: true, order: o };
}

function afterConfirm(o) {
  o.status = OrderStatus.PREPARING;
  o.prep_estimate_minutes = o.lines.reduce((m, l) => Math.max(m, 10), 10);
  if (o.fulfillment_type === "delivery") {
    const assign = assignAvailableDriver(o.delivery_lat, o.delivery_lng);
    if (assign.ok) {
      o.assigned_driver_id = assign.driver.id;
      assign.driver.active_order_id = o.id;
      o.status = OrderStatus.DRIVER_ASSIGNED;
      o.eta_minutes = computeEtaMinutes(o, assign.driver);
    }
  }
}

export function cancelOrder(orderId, by = "customer") {
  const o = orders.get(orderId);
  if (!o) return { ok: false };
  if ([OrderStatus.PREPARING, OrderStatus.CONFIRMED, OrderStatus.DRAFT].includes(o.status) || by === "merchant") {
    o.status = OrderStatus.CANCELLED;
    if (o.assigned_driver_id) releaseDriver(o.assigned_driver_id);
    return { ok: true, order: o };
  }
  return { ok: false, reason: "too_late" };
}

export function getStatusReply(orderId) {
  const o = orders.get(orderId);
  if (!o) return null;
  return {
    reference: o.reference,
    status: o.status,
    eta_minutes: o.eta_minutes,
    driver_id: o.assigned_driver_id,
  };
}
