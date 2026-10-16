"use client";

import { useCallback, useEffect, useState } from "react";
import { useLang } from "@/lib/i18n";
import { useLiveRefresh } from "@/lib/live";

/**
 * Inbox → System: which server settings are in place (AI, WhatsApp, queue, scheduler, email,
 * backups…). Each missing item says in plain words what to set on Railway. Folds itself away
 * once everything required and recommended is done.
 */
export default function LaunchChecklist({ api }) {
  const { t } = useLang();
  const [checks, setChecks] = useState(null);
  const [open, setOpen] = useState(false);

  const load = useCallback(
    () => api("GET", "/admin/system-status")
      .then((data) => setChecks(data?.checks || []))
      .catch(() => setChecks((cur) => cur ?? [])),
    [api],
  );

  useEffect(() => { load(); }, [load]);
  useLiveRefresh(load);

  if (!checks?.length) return null;

  const todo = checks.filter((c) => !c.ok && c.level !== "optional");
  const blocking = todo.some((c) => c.level === "required");
  const shown = open || todo.length > 0;

  return (
    <section className={`launch-list ${blocking ? "bad" : todo.length ? "warn" : "ok"}`}>
      <button type="button" className="launch-head" onClick={() => setOpen(!open)} aria-expanded={shown}>
        <span className="launch-title">{t("ow.launch.title")}</span>
        <span className="launch-sum">
          {todo.length ? t("ow.launch.todo", { n: todo.length }) : t("ow.launch.allSet")}
        </span>
      </button>
      {shown && (
        <ul className="launch-items">
          {checks.map((c) => (
            <li key={c.key} className={c.ok ? "ok" : c.level}>
              <span className="launch-mark" aria-hidden="true">{c.ok ? "✓" : c.level === "required" ? "✕" : "!"}</span>
              <div>
                <p className="launch-name">
                  {t(`ow.launch.${c.key}`)}
                  {!c.ok && <span className="launch-level">{t(`ow.launch.level.${c.level}`)}</span>}
                </p>
                {!c.ok && <p className="launch-fix" dir="auto">{t(`ow.launch.${c.key}.fix`)}</p>}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
