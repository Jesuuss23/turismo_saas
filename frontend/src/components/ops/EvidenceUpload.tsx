"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { fileToUpload } from "@/lib/ops";
import { btnCls, inputCls } from "@/components/crm/ui";

/**
 * Registrar evidencias (PROC-038): imagen o PDF ≤ 8 MB, subida directa a la API con su SHA-256;
 * una foto repetida en la orden se asocia a la existente (OPS-BR-71).
 */
export function EvidenceUpload({ path, services, close, label = "Registrar evidencia" }: {
  path: string; close?: string; label?: string; services?: { id: string; label: string }[];
}) {
  const router = useRouter();
  const [files, setFiles] = useState<File[]>([]);
  const [service, setService] = useState("");
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();

  const submit = () => start(async () => {
    setMsg(null);
    const tooBig = files.find((f) => f.size > 8 * 1024 * 1024);
    if (tooBig) { setMsg({ ok: false, text: `${tooBig.name} supera 8 MB.` }); return; }
    const uploads = await Promise.all(files.map(fileToUpload));
    const r = await callApi(path, "POST", { uploads, reservation_service_id: service || null }, { "Idempotency-Key": crypto.randomUUID() });
    if (r.error) { setMsg({ ok: false, text: r.error }); return; }
    setFiles([]);
    setMsg({ ok: true, text: "Evidencia registrada." });
    if (close) router.push(close, { scroll: false });
    router.refresh();
  });

  return (
    <div className="space-y-3">
      {services && services.length > 0 && (
        <label className="block">
          <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Servicio (opcional)</span>
          <select value={service} onChange={(e) => setService(e.target.value)} className={inputCls} aria-label="Servicio">
            <option value="">Toda la orden</option>
            {services.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
          </select>
        </label>
      )}
      <input type="file" accept="image/*,application/pdf" multiple aria-label="Archivos de evidencia"
        onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 5))} className="block w-full text-sm" />
      <button type="button" onClick={submit} disabled={pending || files.length === 0} className={btnCls}>{pending ? "Subiendo…" : label}</button>
      {msg && <p className={`rounded-md px-3 py-2 text-sm ring-1 ${msg.ok ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : "bg-rose-50 text-rose-800 ring-rose-200"}`}>{msg.text}</p>}
    </div>
  );
}
