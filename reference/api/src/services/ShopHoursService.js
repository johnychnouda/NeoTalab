/**
 * Shop hours service
 * Feature #35
 */
import { query } from "../db.js";

export async function isShopOpen(merchantId) {
  const { rows } = await query(
    "SELECT mode FROM merchants WHERE id = $1", [merchantId]
  );
  if (!rows[0]) return false;
  if (rows[0].mode === "closed") return false;

  const now = new Date();
  const dayOfWeek = now.getDay();
  const currentTime = now.toTimeString().slice(0, 5);

  const { rows: hours } = await query(
    `SELECT opens_at, closes_at, is_closed FROM shop_hours
     WHERE merchant_id = $1 AND day_of_week = $2`,
    [merchantId, dayOfWeek]
  );

  if (!hours[0] || hours[0].is_closed) return false;

  const opensAt = hours[0].opens_at.slice(0, 5);
  const closesAt = hours[0].closes_at.slice(0, 5);

  return currentTime >= opensAt && currentTime <= closesAt;
}

export async function getNextOpeningTime(merchantId, locale = "en") {
  const now = new Date();
  const dayOfWeek = now.getDay();

  const { rows } = await query(
    `SELECT day_of_week, opens_at FROM shop_hours
     WHERE merchant_id = $1 AND is_closed = FALSE ORDER BY day_of_week`,
    [merchantId]
  );

  for (let i = 1; i <= 7; i++) {
    const nextDay = (dayOfWeek + i) % 7;
    const dayHours = rows.find(h => h.day_of_week === nextDay);
    if (dayHours) {
      const days = ["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
      const msgs = {
        en: `We reopen ${i === 1 ? "tomorrow" : `on ${days[nextDay]}`} at ${dayHours.opens_at.slice(0,5)}.`,
        ar: `نعود للعمل ${i === 1 ? "غداً" : `يوم ${days[nextDay]}`} الساعة ${dayHours.opens_at.slice(0,5)}.`,
        fr: `Nous réouvrons ${i === 1 ? "demain" : days[nextDay]} à ${dayHours.opens_at.slice(0,5)}.`,
      };
      return msgs[locale] || msgs.en;
    }
  }
  return "";
}
