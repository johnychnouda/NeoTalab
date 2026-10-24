"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";
import { useLang } from "@/lib/i18n";
import { AuthShell, AuthField, PasswordField, AuthAlert, AuthSubmit, ICONS, EMAIL_RE } from "@/components/auth/AuthShell";

/** "Forgot password?" form, shown in place of the sign-in form. role: "owner" or "merchant". */
export function ForgotPassword({ role, onBack }) {
  const { t } = useLang();
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [emailError, setEmailError] = useState("");

  async function submit(e) {
    e.preventDefault();
    setError("");
    if (!EMAIL_RE.test(email.trim())) { setEmailError(t("auth.badEmail")); return; }
    setBusy(true);
    try {
      await apiFetch(role, "POST", "/auth/forgot-password", { email: email.trim() });
      setSent(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  if (sent) {
    return (
      <>
        <p className="auth-secure" role="status">{t("reset.forgot.sent")}</p>
        <button type="button" className="auth-link" onClick={onBack}>{t("reset.back")}</button>
      </>
    );
  }

  return (
    <form onSubmit={submit} noValidate>
      <p className="auth-secure">{t("reset.forgot.sub")}</p>
      <AuthAlert>{error}</AuthAlert>
      <AuthField
        id={`reset-email-${role}`} label={t("reset.email")} icon={ICONS.mail} error={emailError}
        type="email" inputMode="email" autoComplete="username" autoCapitalize="none" spellCheck={false} autoFocus
        value={email} placeholder={t("auth.emailPh")}
        onChange={(e) => { setEmail(e.target.value); if (emailError) setEmailError(""); }}
      />
      <AuthSubmit busy={busy} busyLabel={t("reset.forgot.send")}>{t("reset.forgot.send")}</AuthSubmit>
      <button type="button" className="auth-link" onClick={onBack}>{t("reset.back")}</button>
    </form>
  );
}

/** The page the emailed link opens: /reset-password?token=…&email=… */
export function ResetPasswordForm() {
  const { t } = useLang();
  const [token, setToken] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const p = new URLSearchParams(window.location.search);
    setToken(p.get("token") || "");
    setEmail(p.get("email") || "");
    setReady(true);
  }, []);

  async function submit(e) {
    e.preventDefault();
    setError("");
    if (password.length < 8) { setError(t("reset.new.short")); return; }
    if (password !== confirm) { setError(t("reset.new.mismatch")); return; }
    setBusy(true);
    try {
      await apiFetch("merchant", "POST", "/auth/reset-password", { email, token, password, password_confirmation: confirm });
      setDone(true);
    } catch (err) {
      setError(err.message || t("reset.new.badLink"));
    } finally {
      setBusy(false);
    }
  }

  if (!ready) return null;
  if (!token || !email) {
    return <AuthShell title={t("reset.new.title")}><AuthAlert>{t("reset.new.badLink")}</AuthAlert></AuthShell>;
  }
  if (done) {
    return <AuthShell title={t("reset.new.title")}><p className="auth-secure" role="status">{t("reset.new.done")}</p></AuthShell>;
  }

  return (
    <AuthShell title={t("reset.new.title")} subtitle={t("reset.new.sub")}>
      <form onSubmit={submit} noValidate>
        <AuthAlert>{error}</AuthAlert>
        <PasswordField
          id="reset-new" label={t("reset.new.password")} autoComplete="new-password"
          value={password} onChange={(e) => setPassword(e.target.value)}
        />
        <PasswordField
          id="reset-confirm" label={t("reset.new.confirm")} autoComplete="new-password"
          value={confirm} onChange={(e) => setConfirm(e.target.value)}
        />
        <AuthSubmit busy={busy} busyLabel={t("reset.new.save")}>{t("reset.new.save")}</AuthSubmit>
      </form>
    </AuthShell>
  );
}
