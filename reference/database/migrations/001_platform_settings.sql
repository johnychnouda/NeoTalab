-- Run if platform_settings is missing on an existing database:
-- psql -U neotalab -d neotalab -f database/migrations/001_platform_settings.sql

CREATE TABLE IF NOT EXISTS platform_settings (
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

INSERT INTO platform_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;
