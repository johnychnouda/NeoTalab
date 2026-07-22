/** Demo tenant — Joe's Snacks */
export const tenant = {
  id: 1,
  name: "Joe's Snacks",
  slug: "joes-snacks",
  auto_accept_orders: true,
  wish_account_phone: "+96170123456",
  wish_amount_tolerance: 1000,
  merchant_locale: "ar",
  geofence_nearby_meters: 500,
  geofence_arrived_meters: 50,
};

export const branch = {
  id: 1,
  tenant_id: 1,
  name: "Jounieh",
  latitude: 33.98,
  longitude: 35.62,
  is_default: true,
};

export const products = [
  { id: 1, name: "Chicken shawarma", base_price: 180000, prep_time_minutes: 10, is_available: true, track_inventory: false },
  { id: 2, name: "Pepsi 330ml", base_price: 50000, prep_time_minutes: 0, is_available: true, track_inventory: true, stock_quantity: 24 },
  { id: 3, name: "Falafel wrap", base_price: 120000, prep_time_minutes: 8, is_available: false },
];

export const drivers = [
  { id: 1, name: "Rami", phone: "+9613999002", status: "available", on_duty: true, active_order_id: null, lat: 33.985, lng: 35.615 },
  { id: 2, name: "Ahmad", phone: "+9613999001", status: "busy", on_duty: true, active_order_id: 999 },
];

export const zones = [
  { id: 1, name: "Jounieh — core", fee: 25000, min_order_amount: 200000, radius_meters: 5000, is_active: true },
];

export const customers = new Map();

export const orders = new Map();
export let orderSeq = 42;
