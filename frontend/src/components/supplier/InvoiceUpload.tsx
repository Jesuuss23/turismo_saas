"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { fileToUpload } from "@/lib/ops";
import { btnCls, inputCls } from "@/components/crm/ui";

/** Subir factura (SUP-BR-40/41): número serie-correlativo, fecha, importe y comprobante PDF/XML/ZIP ≤ 8 MB. */
export function InvoiceUpload({ payableId, balance, currency, close }: { payableId: string; balance: string; currency: string; close: string }) {
  const router = useRouter();
  const [file, setFile] = useState<File | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string; warnings?: string[] } | null>(null);
  const [idem] = useState(() => crypto.randomUUID());
  const [pending, start] = useTransition();

  const submit = (form: HTMLFormElement) => start(async () => {
    setMsg(null);
    if (!file) { setMsg({ ok: false, text: "Adjunte el comprobante." }); return; }
    if (file.size > 8 * 1024 * 1024) { setMsg({ ok: false, text: `${file.name} supera 8 MB.` }); return; }
    const data = new FormData(form);
    const r = await callApi(`/supplier/payables/${payableId}/invoices`, "POST", {
      number: data.get("number"), issue_date: data.get("issue_date"), amount: data.get("amount"), file: await fileToUpload(file),
    }, { "Idempotency-Key": idem });
    if (r.error) { setMsg({ ok: false, text: [r.error, ...Object.values(r.fieldErrors ?? {}).flat()].join(" · ") }); return; }
    setMsg({ ok: true, text: "Factura registrada. Finanzas la validará.", warnings: r.warnings });
    router.refresh();
    if (!r.warnings?.length) router.push(close, { scroll: false });
  });

  return (
    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); submit(e.currentTarget); }}>
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Número de factura</span>
        <input name="number" required placeholder="F001-000123" pattern="[A-Za-z0-9]{4}-\d{1,8}" title="Serie de 4 caracteres, guion y correlativo" className={inputCls} /></label>
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Fecha de emisión</span>
        <input name="issue_date" type="date" required className={inputCls} /></label>
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Importe ({currency})</span>
        <input name="amount" type="number" step="0.01" min="0.01" required defaultValue={balance} className={inputCls} /></label>
      <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Comprobante (PDF, XML o ZIP)</span>
        <input type="file" accept=".pdf,.xml,.zip,application/pdf,application/xml,text/xml,application/zip" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="block w-full text-sm" /></label>
      <button type="submit" disabled={pending} className={btnCls}>{pending ? "Subiendo…" : "Subir factura"}</button>
      {msg && <p className={`rounded-md px-3 py-2 text-sm ring-1 ${msg.ok ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : "bg-rose-50 text-rose-800 ring-rose-200"}`}>{msg.text}</p>}
      {msg?.warnings?.map((w) => <p key={w} className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{w}</p>)}
    </form>
  );
}
