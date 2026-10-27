"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { callApi } from "@/app/(app)/crm/actions";
import { fileToUpload } from "@/lib/ops";
import { btnCls, inputCls } from "@/components/crm/ui";

const MAX = 10 * 1024 * 1024;

/** Cargar / Reemplazar un documento (DOC-BR-013: PDF, JPG o PNG ≤ 10 MB; el backend pasa el antivirus). */
export function TravelDocUpload({ path, withIdentity, docNumber, expiry, replace }: {
  path: string; withIdentity: boolean; docNumber?: string | null; expiry?: string | null; replace: boolean;
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [key, setKey] = useState(() => crypto.randomUUID());

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const file = form.get("file");
    if (!(file instanceof File) || file.size === 0) return setMsg({ ok: false, text: "Seleccione el archivo." });
    if (file.size > MAX) return setMsg({ ok: false, text: "El archivo supera 10 MB." });
    setBusy(true);
    setMsg(null);
    const upload = await fileToUpload(file);
    const res = await callApi(path, "POST", {
      file_name: upload.file_name, mime_type: upload.mime_type, content_base64: upload.content_base64,
      doc_number: form.get("doc_number") || null, expiry_date: form.get("expiry_date") || null,
    }, { "Idempotency-Key": key });
    setBusy(false);
    if (res.error) return setMsg({ ok: false, text: res.error });
    setKey(crypto.randomUUID());
    setMsg({ ok: true, text: res.info ?? "Documento cargado" });
    router.refresh();
  }

  return (
    <form onSubmit={submit} className="space-y-2">
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Archivo (PDF, JPG o PNG, máx. 10 MB)</span>
        <input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required className="block w-full text-sm" /></label>
      {withIdentity && (
        <div className="grid grid-cols-2 gap-2">
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">N.º de documento</span>
            <input name="doc_number" maxLength={40} defaultValue={docNumber ?? ""} className={inputCls} /></label>
          <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Vencimiento</span>
            <input name="expiry_date" type="date" defaultValue={expiry ?? ""} className={inputCls} /></label>
        </div>
      )}
      <button type="submit" disabled={busy} className={btnCls}>{busy ? "Cargando…" : replace ? "Reemplazar archivo" : "Cargar archivo"}</button>
      {msg && <p className={`rounded-md px-3 py-2 text-sm ring-1 ${msg.ok ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : "bg-rose-50 text-rose-800 ring-rose-200"}`} role="status">{msg.text}</p>}
    </form>
  );
}
