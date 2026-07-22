/**
 * Background scheduler — sends subscription renewal WhatsApp reminders.
 */
import { logger } from "../utils/logger.js";
import { processRenewalReminders } from "../services/RenewalReminderService.js";

const INTERVAL_MS = 6 * 60 * 60 * 1000; // every 6 hours
const STARTUP_DELAY_MS = 15 * 1000;

let running = false;

async function tick() {
  if (running) return;
  running = true;
  try {
    await processRenewalReminders();
  } catch (err) {
    logger.error("Renewal reminder job failed", { error: err.message });
  } finally {
    running = false;
  }
}

export function startRenewalReminderJob() {
  setTimeout(tick, STARTUP_DELAY_MS);
  setInterval(tick, INTERVAL_MS);
  logger.info("Renewal reminder scheduler started", {
    intervalHours: INTERVAL_MS / 3600000,
    startupDelaySec: STARTUP_DELAY_MS / 1000,
  });
}
