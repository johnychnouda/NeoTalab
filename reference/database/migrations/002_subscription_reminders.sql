-- Subscription renewal reminders (auto WhatsApp before renewal)
-- psql -U neotalab -d neotalab -f database/migrations/002_subscription_reminders.sql

ALTER TABLE merchants ADD COLUMN IF NOT EXISTS last_payment_at TIMESTAMPTZ;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS billing_cycle TEXT NOT NULL DEFAULT 'monthly';
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS yearly_fee NUMERIC(10,2);

CREATE TABLE IF NOT EXISTS subscription_reminder_log (
  id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  merchant_id   UUID NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  renewal_date  DATE NOT NULL,
  sent_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (merchant_id, renewal_date)
);

CREATE INDEX IF NOT EXISTS idx_reminder_log_merchant ON subscription_reminder_log(merchant_id);
