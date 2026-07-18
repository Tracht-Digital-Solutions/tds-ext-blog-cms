import { useEffect, useState } from "react";

interface Masked {
  key: string;
  secret: boolean;
  configured?: boolean;
  last4?: string | null;
  value?: string;
}

const api = (path: string, init?: RequestInit) => fetch(path, { credentials: "include", ...init });
const NS = "/admin/settings/blog-cms";

/**
 * Blog-CMS settings section — DeepL key + auto-translate flag + rebuild token,
 * persisted in the core's runtime settings store (`/admin/settings/blog-cms`,
 * admin-only). Secrets come back masked (configured + last4) and a blank secret
 * on save keeps the existing value, so the raw key never round-trips to the UI.
 * The extension backend reads these DB-first with an env fallback.
 */
export default function BlogSettings() {
  const [loaded, setLoaded] = useState(false);
  const [deeplState, setDeeplState] = useState<Masked | null>(null);
  const [rebuildState, setRebuildState] = useState<Masked | null>(null);
  const [autoTranslate, setAutoTranslate] = useState(true);
  const [deeplInput, setDeeplInput] = useState("");
  const [rebuildInput, setRebuildInput] = useState("");
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const res = await api(NS);
    if (!res.ok) {
      setStatus(res.status === 403 || res.status === 401 ? "Nur für Administratoren." : `Fehler (HTTP ${res.status}).`);
      setLoaded(true);
      return;
    }
    const d = await res.json();
    const map = new Map<string, Masked>((d.settings ?? []).map((s: Masked) => [s.key, s]));
    setDeeplState(map.get("deepl_api_key") ?? null);
    setRebuildState(map.get("rebuild_token") ?? null);
    const at = map.get("auto_translate");
    setAutoTranslate(at?.value !== "0");
    setLoaded(true);
  };

  useEffect(() => {
    void load();
  }, []);

  const save = async () => {
    setBusy(true);
    setStatus(null);
    const settings: Masked[] = [
      { key: "deepl_api_key", secret: true, value: deeplInput.trim() },
      { key: "rebuild_token", secret: true, value: rebuildInput.trim() },
      { key: "auto_translate", secret: false, value: autoTranslate ? "1" : "0" },
    ];
    const res = await api(NS, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ settings }),
    });
    setBusy(false);
    if (res.ok) {
      setDeeplInput("");
      setRebuildInput("");
      setStatus("Gespeichert.");
      void load();
    } else {
      setStatus(`Fehler (HTTP ${res.status}).`);
    }
  };

  const secretHint = (s: Masked | null) =>
    s?.configured ? `konfiguriert (…${s.last4 ?? "????"})` : "nicht konfiguriert";

  if (!loaded) return <p>Wird geladen …</p>;

  return (
    <div className="blog-settings space-y-4">
      <label className="block">
        <span className="text-sm">DeepL API-Key <em className="opacity-60">({secretHint(deeplState)})</em></span>
        <input
          type="password"
          value={deeplInput}
          onChange={(e) => setDeeplInput(e.target.value)}
          placeholder="Neuen Schlüssel setzen (leer = behalten)"
          autoComplete="off"
        />
      </label>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={autoTranslate} onChange={(e) => setAutoTranslate(e.target.checked)} />
        <span>Automatische Übersetzung (DeepL) aktiv</span>
      </label>

      <label className="block">
        <span className="text-sm">Rebuild-Token (GitHub PAT) <em className="opacity-60">({secretHint(rebuildState)})</em></span>
        <input
          type="password"
          value={rebuildInput}
          onChange={(e) => setRebuildInput(e.target.value)}
          placeholder="Neuen Token setzen (leer = behalten)"
          autoComplete="off"
        />
      </label>

      {status ? <p className="status-pill status-pill--info">{status}</p> : null}
      <button type="button" onClick={save} disabled={busy}>Speichern</button>
    </div>
  );
}
