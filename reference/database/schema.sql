-- ============================================================
-- NeoTalab — PostgreSQL Schema
-- AI WhatsApp Commerce OS
-- ============================================================

-- Extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ============================================================
-- ENUMS
-- ============================================================

CREATE TYPE user_role AS ENUM ('owner', 'merchant', 'driver');
CREATE TYPE merchant_status AS ENUM ('pending', 'active', 'suspended', 'cancelled');
CREATE TYPE subscription_plan AS ENUM ('basic', 'pro', 'enterprise');
CREATE TYPE order_status AS ENUM (
  'draft', 'pending_payment', 'confirmed', 'preparing',
  'ready', 'assigned', 'picked_up', 'delivered', 'cancelled', 'rejected'
);
CREATE TYPE fulfillment_type AS ENUM ('delivery', 'pickup');
CREATE TYPE payment_method AS ENUM ('cash', 'wish', 'bob_finance');
CREATE TYPE payment_status AS ENUM ('pending', 'paid', 'failed', 'refunded');
CREATE TYPE driver_status AS ENUM ('on_duty', 'off_duty', 'on_break', 'paused');
CREATE TYPE driver_availability AS ENUM ('available', 'busy');
CREATE TYPE shop_mode AS ENUM ('auto', 'busy', 'manual', 'closed');
CREATE TYPE incident_type AS ENUM ('no_answer', 'wrong_address', 'customer_refused', 'damaged', 'other');
CREATE TYPE feedback_sentiment AS ENUM ('positive', 'neutral', 'negative');
CREATE TYPE zone_type AS ENUM ('polygon', 'radius');

-- ============================================================
-- PLATFORM OWNER
-- ============================================================

CREATE TABLE owners (
  id                    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  name                  TEXT NOT NULL,
  email                 TEXT UNIQUE NOT NULL,
  password_hash         TEXT NOT NULL,
  phone                 TEXT,
  password_changed_at   TIMESTAMPTZ,
  tokens_valid_after    TIMESTAMPTZ NOT NULL DEFAULT '1970-01-01',
  created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- MERCHANTS
-- ============================================================

CREATE TABLE merchants (
  id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  owner_id            UUID NOT NULL REFERENCES owners(id) ON DELETE RESTRICT,

  -- Shop identity
  shop_name           TEXT NOT NULL,
  shop_name_ar        TEXT,
  shop_name_fr        TEXT,
  logo_url            TEXT,
  description         TEXT,

  -- Contact
  whatsapp_number     TEXT UNIQUE NOT NULL,
  ops_whatsapp        TEXT NOT NULL,
  email               TEXT,
  address             TEXT,

  -- Account
  password_hash       TEXT NOT NULL,
  status              merchant_status NOT NULL DEFAULT 'pending',
  plan                subscription_plan NOT NULL DEFAULT 'basic',
  trial_ends_at       TIMESTAMPTZ,
  subscription_starts TIMESTAMPTZ,
  subscription_ends   TIMESTAMPTZ,
  last_payment_at     TIMESTAMPTZ,
  billing_cycle       TEXT NOT NULL DEFAULT 'monthly',
  monthly_fee         NUMERIC(10,2),
  yearly_fee          NUMERIC(10,2),

  -- Shop config
  mode                shop_mode NOT NULL DEFAULT 'auto',
  auto_accept_orders  BOOLEAN NOT NULL DEFAULT TRUE,
  default_locale      TEXT NOT NULL DEFAULT 'ar',
  currency            TEXT NOT NULL DEFAULT 'USD',

  -- WhatsApp API
  wa_phone_id         TEXT,
  wa_access_token     TEXT,
  wa_webhook_secret   TEXT,

  -- Wish payment
  wish_number         TEXT,
  wish_auto_confirm   BOOLEAN NOT NULL DEFAULT TRUE,
  wish_timeout_mins   INT NOT NULL DEFAULT 10,

  -- Owner notes
  owner_notes         TEXT,

  created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_merchants_status ON merchants(status);
CREATE INDEX idx_merchants_whatsapp ON merchants(whatsapp_number);

-- ============================================================
-- SESSIONS (auth tokens)
-- ============================================================

CREATE TABLE sessions (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  entity_type   user_role NOT NULL,
  entity_id     UUID NOT NULL,
  token_hash    TEXT UNIQUE NOT NULL,
  ip_address    TEXT,
  user_agent    TEXT,
  expires_at    TIMESTAMPTZ NOT NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sessions_token ON sessions(token_hash);
CREATE INDEX idx_sessions_entity ON sessions(entity_type, entity_id);

-- ============================================================
-- ONBOARDING REQUESTS
-- ============================================================

CREATE TABLE onboarding_requests (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  shop_name     TEXT NOT NULL,
  contact_name  TEXT NOT NULL,
  whatsapp      TEXT NOT NULL,
  email         TEXT,
  city          TEXT,
  business_type TEXT,
  message       TEXT,
  status        TEXT NOT NULL DEFAULT 'pending',
  reviewed_by   UUID REFERENCES owners(id),
  reviewed_at   TIMESTAMPTZ,
  merchant_id   UUID,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- MENU
-- ============================================================

CREATE TABLE categories (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id   UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  name_ar       TEXT,
  name_fr       TEXT,
  sort_order    INT NOT NULL DEFAULT 0,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_categories_merchant ON categories(merchant_id);

CREATE TABLE products (
  id               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id      UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  category_id      UUID REFERENCES categories(id) ON DELETE SET NULL,
  name             TEXT NOT NULL,
  name_ar          TEXT,
  name_fr          TEXT,
  description      TEXT,
  description_ar   TEXT,
  price            NUMERIC(10,2) NOT NULL,
  image_url        TEXT,
  is_active        BOOLEAN NOT NULL DEFAULT TRUE,
  is_sold_out      BOOLEAN NOT NULL DEFAULT FALSE,
  prep_time_mins   INT NOT NULL DEFAULT 15,
  sort_order       INT NOT NULL DEFAULT 0,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_products_merchant ON products(merchant_id);
CREATE INDEX idx_products_category ON products(category_id);

CREATE TABLE modifier_groups (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  product_id    UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  name_ar       TEXT,
  name_fr       TEXT,
  required      BOOLEAN NOT NULL DEFAULT FALSE,
  min_select    INT NOT NULL DEFAULT 0,
  max_select    INT NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0
);

CREATE TABLE modifiers (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  group_id      UUID NOT NULL REFERENCES modifier_groups(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  name_ar       TEXT,
  name_fr       TEXT,
  price_delta   NUMERIC(10,2) NOT NULL DEFAULT 0,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order    INT NOT NULL DEFAULT 0
);

-- ============================================================
-- DELIVERY ZONES
-- ============================================================

CREATE TABLE delivery_zones (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id   UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  zone_type     zone_type NOT NULL DEFAULT 'radius',
  coordinates   JSONB,
  delivery_fee  NUMERIC(10,2) NOT NULL DEFAULT 0,
  minimum_order NUMERIC(10,2) NOT NULL DEFAULT 0,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_zones_merchant ON delivery_zones(merchant_id);

-- ============================================================
-- SHOP HOURS
-- ============================================================

CREATE TABLE shop_hours (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id   UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  day_of_week   INT NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
  opens_at      TIME NOT NULL,
  closes_at     TIME NOT NULL,
  is_closed     BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE UNIQUE INDEX idx_shop_hours_day ON shop_hours(merchant_id, day_of_week);

-- ============================================================
-- CUSTOMERS
-- ============================================================

CREATE TABLE customers (
  id                UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id       UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  phone             TEXT NOT NULL,
  name              TEXT,
  preferred_locale  TEXT NOT NULL DEFAULT 'ar',
  is_blocked        BOOLEAN NOT NULL DEFAULT FALSE,
  block_reason      TEXT,
  total_orders      INT NOT NULL DEFAULT 0,
  total_spent       NUMERIC(10,2) NOT NULL DEFAULT 0,
  last_order_at     TIMESTAMPTZ,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_customers_merchant_phone ON customers(merchant_id, phone);

CREATE TABLE customer_addresses (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  customer_id   UUID NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  label         TEXT,
  lat           NUMERIC(10,7),
  lng           NUMERIC(10,7),
  building      TEXT NOT NULL,
  floor         TEXT,
  additional    TEXT,
  zone_id       UUID REFERENCES delivery_zones(id),
  is_default    BOOLEAN NOT NULL DEFAULT FALSE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_addresses_customer ON customer_addresses(customer_id);

-- ============================================================
-- DRIVERS
-- ============================================================

CREATE TABLE drivers (
  id                UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id       UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  name              TEXT NOT NULL,
  phone             TEXT NOT NULL,
  whatsapp_number   TEXT NOT NULL,
  password_hash     TEXT NOT NULL,
  status            driver_status NOT NULL DEFAULT 'off_duty',
  availability      driver_availability NOT NULL DEFAULT 'available',
  active_order_id   UUID,
  last_lat          NUMERIC(10,7),
  last_lng          NUMERIC(10,7),
  last_ping_at      TIMESTAMPTZ,
  cash_on_hand      NUMERIC(10,2) NOT NULL DEFAULT 0,
  total_deliveries  INT NOT NULL DEFAULT 0,
  is_active         BOOLEAN NOT NULL DEFAULT TRUE,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_drivers_merchant ON drivers(merchant_id);
CREATE UNIQUE INDEX idx_drivers_merchant_phone ON drivers(merchant_id, phone);

CREATE TABLE driver_shifts (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  driver_id       UUID NOT NULL REFERENCES drivers(id) ON DELETE CASCADE,
  started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  ended_at        TIMESTAMPTZ,
  deliveries      INT NOT NULL DEFAULT 0,
  cash_collected  NUMERIC(10,2) NOT NULL DEFAULT 0
);

-- ============================================================
-- ORDERS
-- ============================================================

CREATE TABLE orders (
  id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id         UUID NOT NULL REFERENCES merchants(id) ON DELETE RESTRICT,
  customer_id         UUID NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
  driver_id           UUID REFERENCES drivers(id) ON DELETE SET NULL,

  status              order_status NOT NULL DEFAULT 'draft',
  fulfillment_type    fulfillment_type NOT NULL DEFAULT 'delivery',

  subtotal            NUMERIC(10,2) NOT NULL DEFAULT 0,
  delivery_fee        NUMERIC(10,2) NOT NULL DEFAULT 0,
  discount            NUMERIC(10,2) NOT NULL DEFAULT 0,
  total               NUMERIC(10,2) NOT NULL DEFAULT 0,

  payment_method      payment_method,
  payment_status      payment_status NOT NULL DEFAULT 'pending',
  wish_reference      TEXT,
  wish_amount_sent    NUMERIC(10,2),

  delivery_lat        NUMERIC(10,7),
  delivery_lng        NUMERIC(10,7),
  delivery_building   TEXT,
  delivery_floor      TEXT,
  delivery_additional TEXT,
  zone_id             UUID REFERENCES delivery_zones(id),

  prep_time_mins      INT,
  eta_mins            INT,
  scheduled_for       TIMESTAMPTZ,
  accepted_at         TIMESTAMPTZ,
  preparing_at        TIMESTAMPTZ,
  ready_at            TIMESTAMPTZ,
  assigned_at         TIMESTAMPTZ,
  picked_up_at        TIMESTAMPTZ,
  delivered_at        TIMESTAMPTZ,
  cancelled_at        TIMESTAMPTZ,

  customer_note       TEXT,
  merchant_note       TEXT,
  cancel_reason       TEXT,
  reject_reason       TEXT,
  cancelled_by        TEXT,

  feedback_text       TEXT,
  feedback_sentiment  feedback_sentiment,

  raw_message         TEXT,
  locale              TEXT NOT NULL DEFAULT 'ar',
  has_incident        BOOLEAN NOT NULL DEFAULT FALSE,

  created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_orders_merchant ON orders(merchant_id);
CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_orders_driver ON orders(driver_id);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_created ON orders(created_at DESC);

ALTER TABLE drivers ADD CONSTRAINT fk_driver_active_order
  FOREIGN KEY (active_order_id) REFERENCES orders(id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED;

CREATE TABLE order_items (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  order_id      UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id    UUID NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
  product_name  TEXT NOT NULL,
  unit_price    NUMERIC(10,2) NOT NULL,
  quantity      INT NOT NULL DEFAULT 1,
  modifiers     JSONB,
  item_total    NUMERIC(10,2) NOT NULL
);

CREATE INDEX idx_order_items_order ON order_items(order_id);

CREATE TABLE order_incidents (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  order_id      UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  driver_id     UUID REFERENCES drivers(id),
  incident_type incident_type NOT NULL,
  notes         TEXT,
  resolved      BOOLEAN NOT NULL DEFAULT FALSE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- DRIVER DISPATCH QUEUE
-- ============================================================

CREATE TABLE dispatch_attempts (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  order_id      UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  driver_id     UUID NOT NULL REFERENCES drivers(id) ON DELETE CASCADE,
  sent_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  responded_at  TIMESTAMPTZ,
  response      TEXT,    -- 'accepted' | 'rejected' | 'timeout'
  timeout_at    TIMESTAMPTZ NOT NULL
);

CREATE INDEX idx_dispatch_order ON dispatch_attempts(order_id);

-- ============================================================
-- TRACKING
-- ============================================================

CREATE TABLE tracking_pings (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  order_id      UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  driver_id     UUID NOT NULL REFERENCES drivers(id) ON DELETE CASCADE,
  lat           NUMERIC(10,7) NOT NULL,
  lng           NUMERIC(10,7) NOT NULL,
  event         TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_tracking_order ON tracking_pings(order_id, created_at DESC);

-- ============================================================
-- ANALYTICS
-- ============================================================

CREATE TABLE daily_summaries (
  id                UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id       UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  summary_date      DATE NOT NULL,
  total_orders      INT NOT NULL DEFAULT 0,
  completed_orders  INT NOT NULL DEFAULT 0,
  cancelled_orders  INT NOT NULL DEFAULT 0,
  rejected_orders   INT NOT NULL DEFAULT 0,
  gross_revenue     NUMERIC(10,2) NOT NULL DEFAULT 0,
  delivery_fees     NUMERIC(10,2) NOT NULL DEFAULT 0,
  avg_order_value   NUMERIC(10,2) NOT NULL DEFAULT 0,
  avg_prep_mins     NUMERIC(6,2),
  avg_delivery_mins NUMERIC(6,2),
  new_customers     INT NOT NULL DEFAULT 0,
  repeat_customers  INT NOT NULL DEFAULT 0,
  cash_orders       INT NOT NULL DEFAULT 0,
  wish_orders       INT NOT NULL DEFAULT 0,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_daily_summaries_date ON daily_summaries(merchant_id, summary_date);

-- ============================================================
-- WHATSAPP MESSAGE LOG (idempotency + audit)
-- ============================================================

CREATE TABLE whatsapp_messages (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id   UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  wa_message_id TEXT UNIQUE NOT NULL,
  direction     TEXT NOT NULL,
  from_number   TEXT NOT NULL,
  to_number     TEXT NOT NULL,
  message_type  TEXT NOT NULL,
  content       JSONB,
  order_id      UUID REFERENCES orders(id),
  processed     BOOLEAN NOT NULL DEFAULT FALSE,
  error         TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_wa_messages_merchant ON whatsapp_messages(merchant_id, created_at DESC);
CREATE INDEX idx_wa_messages_wa_id ON whatsapp_messages(wa_message_id);

-- ============================================================
-- PLATFORM STATS (owner-level)
-- ============================================================

CREATE TABLE platform_daily_stats (
  id                UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  stat_date         DATE NOT NULL UNIQUE,
  active_merchants  INT NOT NULL DEFAULT 0,
  total_orders      INT NOT NULL DEFAULT 0,
  total_revenue     NUMERIC(10,2) NOT NULL DEFAULT 0,
  new_merchants     INT NOT NULL DEFAULT 0,
  new_customers     INT NOT NULL DEFAULT 0,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================================
-- TRIGGERS: updated_at
-- ============================================================

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_owners_updated     BEFORE UPDATE ON owners     FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_merchants_updated  BEFORE UPDATE ON merchants  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_products_updated   BEFORE UPDATE ON products   FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_orders_updated     BEFORE UPDATE ON orders     FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_customers_updated  BEFORE UPDATE ON customers  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_drivers_updated    BEFORE UPDATE ON drivers    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- PLATFORM SETTINGS (singleton row)
-- ============================================================

CREATE TABLE platform_settings (
  id                        INT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  platform_name             TEXT NOT NULL DEFAULT 'NeoTalab',
  currency                  TEXT NOT NULL DEFAULT 'USD',
  timezone                  TEXT NOT NULL DEFAULT 'Asia/Beirut',
  subscription_price        NUMERIC(10,2) NOT NULL DEFAULT 29,
  subscription_yearly_price NUMERIC(10,2) NOT NULL DEFAULT 290,
  trial_days                INT NOT NULL DEFAULT 7,
  grace_period_days         INT NOT NULL DEFAULT 3,
  wa_phone_id               TEXT,
  wa_access_token           TEXT,
  wa_verify_token           TEXT,
  overdue_remind_days       INT NOT NULL DEFAULT 3,
  token_warn_days           INT NOT NULL DEFAULT 7,
  overdue_reminder_msg      TEXT,
  updated_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TRIGGER trg_platform_settings_updated
  BEFORE UPDATE ON platform_settings FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- SUBSCRIPTION REMINDER LOG (dedup auto WhatsApp sends)
-- ============================================================

CREATE TABLE subscription_reminder_log (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id   UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  renewal_date  DATE NOT NULL,
  sent_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (merchant_id, renewal_date)
);

CREATE INDEX idx_reminder_log_merchant ON subscription_reminder_log(merchant_id);

-- ============================================================
-- SEED: Default owner (password: neotalab2025)
-- ============================================================

INSERT INTO owners (id, name, email, password_hash, phone)
VALUES (
  'a0000000-0000-0000-0000-000000000001',
  'Johny',
  'johnychnouda@gmail.com',
  '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TiGniYE6Rd6vYgf0J9q.hJrOg/Mu',
  '+961'
);

INSERT INTO platform_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;
