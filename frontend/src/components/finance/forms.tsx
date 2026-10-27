"use client";

import { useEffect, useMemo, useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, inputCls } from "@/components/crm/ui";
import { fileToUpload } from "@/lib/ops";
import { money, type CheckItem, type Figures } from "@/lib/finance";
import { Checklist } from "./ui";

export type Method = { code: string; label: string; requires_reference: boolean; is_b2b_credit: boolean };

function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: React.ReactNode }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

function ErrorBox({ error, fields }: { error?: string | null; fields?: Record<string, string[]> }) {
  if (!error) return null;
  return (
    <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200" role="alert">
      {error}{fields && <span className="mt-1 block text-xs">{Object.values(fields).flat().join(" · ")}</span>}
    </p>
  );
}

/** Campos del registro de pago (PROC-042): la referencia es obligatoria según el medio (FIN-BR-09); TC si la CxC es en USD. */
export function PaymentFields({ methods, currency, amount, today }: { methods: Method[]; currency: string; amount: string; today: string }) {
  const usable = methods.filter((m) => !m.is_b2b_credit);
  const [method, setMethod] = useState(usable.find((m) => m.code === "transferencia_bcp")?.label ?? usable[0]?.label ?? "");
  const requires = usable.find((m) => m.label === method)?.requires_reference ?? false;
  return (
    <>
      <Field label="Medio de pago">
        <select aria-label="Medio de pago" name="method" value={method} onChange={(e) => setMethod(e.target.value)} required className={inputCls}>
          {usable.map((m) => <option key={m.code} value={m.label}>{m.label}</option>)}
        </select>
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label={`Monto (${currency})`}><input name="amount" type="number" step="0.01" min="0.01" defaultValue={amount} required className={inputCls} /></Field>
        <Field label="Fecha de pago"><input name="paid_on" type="date" defaultValue={today} max={today} required className={inputCls} /></Field>
      </div>
      <Field label={requires ? "N° de operación / referencia" : "Referencia (opcional)"} hint={requires ? "Obligatoria para este medio (FIN-BR-09)." : undefined}>
        <input name="reference" required={requires} maxLength={200} className={inputCls} />
      </Field>
      {currency !== "PEN" && <Field label="Tipo de cambio del día (opcional)" hint="Si lo deja vacío se usa el TC del día (SBS)."><input name="fx_rate" type="number" step="0.000001" min="0" className={inputCls} /></Field>}
      <Field label="Nota (opcional)"><input name="note" maxLength={500} className={inputCls} /></Field>
    </>
  );
}

type Draft = {
  receivable?: { id: string; code: string; concept: string; amount: string; paid: string; status: string };
  reservation: string | null; client?: string | null; currency: string; doc_type: string; customer_doc_type: string; customer_doc_number: string;
  customer_name: string; customer_address: string | null; domiciled: boolean; max: string; amount: string; fx_rate: string | null; description: string;
  ref_invoice_id?: string; ref_number?: string;
};
type Validation = { ok: boolean; checks: CheckItem[]; error: { code: string; message: string } | null; totals: { taxable: string; igv: string; total: string; currency: string; in_words?: string } };

const DOC_TYPES = [["RUC", "RUC"], ["DNI", "DNI"], ["CE", "Carné de extranjería"], ["PASAPORTE", "Pasaporte"], ["SIN_DOC", "Sin documento (< S/ 700)"]];

/** Modal de emisión (PROC-050) con validación fiscal en vivo contra POST /finance/invoices/validate. */
export function InvoiceForm({ draft, creditReasons, back }: { draft: Draft; creditReasons?: Record<string, string>; back: string }) {
  const router = useRouter();
  const isCredit = !!draft.ref_invoice_id;
  const [form, setForm] = useState({
    doc_type: isCredit ? "07" : draft.doc_type, customer_doc_type: draft.customer_doc_type, customer_doc_number: draft.customer_doc_number,
    customer_name: draft.customer_name, customer_address: draft.customer_address ?? "", domiciled: draft.domiciled, amount: draft.amount,
    description: draft.description, credit_note_reason_code: "01", credit_note_reason: "",
  });
  const [check, setCheck] = useState<Validation | null>(null);
  const [error, setError] = useState<{ message: string; fields?: Record<string, string[]> } | null>(null);
  const [pending, start] = useTransition();
  const idem = useRef(crypto.randomUUID());
  const payload = useMemo(() => ({
    ...form, receivable_id: draft.receivable?.id ?? null, ref_invoice_id: draft.ref_invoice_id ?? null, fx_rate: draft.fx_rate,
    ...(isCredit ? {} : { credit_note_reason_code: null, credit_note_reason: null }),
  }), [form, draft, isCredit]);

  useEffect(() => {
    const t = setTimeout(async () => {
      const res = await callApi("/finance/invoices/validate", "POST", payload);
      if (res.ok) setCheck(res.data as Validation);
    }, 300);
    return () => clearTimeout(t);
  }, [payload]);

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setForm((f) => ({ ...f, [k]: k === "domiciled" ? (e.target as HTMLInputElement).checked : e.target.value }));

  function submit(e: React.FormEvent) {
    e.preventDefault();
    start(async () => {
      const res = await callApi("/finance/invoices", "POST", payload, { "Idempotency-Key": idem.current });
      if (!res.ok) {
        setError({ message: res.error ?? "No se pudo emitir", fields: res.fieldErrors });
        if (res.code) idem.current = crypto.randomUUID();
        return;
      }
      router.push(`/finanzas/facturacion/${(res.data as { number: string }).number}`);
      router.refresh();
    });
  }

  const totals = check?.totals;
  return (
    <form onSubmit={submit} className="space-y-3" aria-label="Emitir comprobante">
      <p className="text-sm text-slate-600">
        {isCredit ? <>Nota de crédito sobre <span className="font-mono">{draft.ref_number}</span></> : <>{draft.receivable?.code} · {draft.receivable?.concept}</>}
        {draft.reservation && <> · {draft.reservation}</>} · máximo facturable {money(draft.max, draft.currency)}
      </p>
      {!isCredit && (
        <Field label="Tipo de comprobante">
          <select aria-label="Tipo de comprobante" value={form.doc_type} onChange={set("doc_type")} className={inputCls} name="doc_type">
            <option value="01">Factura (01)</option><option value="03">Boleta (03)</option>
          </select>
        </Field>
      )}
      {isCredit && creditReasons && (
        <>
          <Field label="Motivo SUNAT">
            <select aria-label="Motivo SUNAT" value={form.credit_note_reason_code} onChange={set("credit_note_reason_code")} className={inputCls} name="credit_note_reason_code">
              {Object.entries(creditReasons).map(([k, v]) => <option key={k} value={k}>{k} · {v}</option>)}
            </select>
          </Field>
          <Field label="Sustento"><input value={form.credit_note_reason} onChange={set("credit_note_reason")} className={inputCls} name="credit_note_reason" required maxLength={255} /></Field>
        </>
      )}
      <div className="grid grid-cols-2 gap-3">
        <Field label="Tipo de documento">
          <select aria-label="Tipo de documento" value={form.customer_doc_type} onChange={set("customer_doc_type")} className={inputCls} name="customer_doc_type" disabled={isCredit}>
            {DOC_TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </Field>
        <Field label="N° de documento"><input value={form.customer_doc_number} onChange={set("customer_doc_number")} className={inputCls} name="customer_doc_number" disabled={isCredit} /></Field>
      </div>
      <Field label="Razón social / nombre"><input value={form.customer_name} onChange={set("customer_name")} className={inputCls} name="customer_name" disabled={isCredit} /></Field>
      <Field label="Dirección"><input value={form.customer_address} onChange={set("customer_address")} className={inputCls} name="customer_address" disabled={isCredit} /></Field>
      {!isCredit && (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={form.domiciled} onChange={set("domiciled")} name="domiciled" />
          Cliente domiciliado en Perú (si no, exportación de servicios sin IGV)
        </label>
      )}
      <Field label={`Importe total (${draft.currency})`}><input value={form.amount} onChange={set("amount")} type="number" step="0.01" min="0" className={inputCls} name="amount" /></Field>
      <Field label="Descripción"><textarea value={form.description} onChange={set("description")} className={inputCls} rows={2} name="description" /></Field>

      <section className="rounded-md border border-slate-200 bg-slate-50 p-3" aria-live="polite">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Validación fiscal</p>
        {check ? <Checklist items={check.checks} /> : <p className="text-sm text-slate-500">Validando…</p>}
        {totals && (
          <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
            <div><dt className="text-xs text-slate-500">Base imponible</dt><dd>{money(totals.taxable, totals.currency)}</dd></div>
            <div><dt className="text-xs text-slate-500">IGV 18 %</dt><dd>{money(totals.igv, totals.currency)}</dd></div>
            <div><dt className="text-xs text-slate-500">Total</dt><dd className="font-semibold">{money(totals.total, totals.currency)}</dd></div>
          </dl>
        )}
      </section>
      <ErrorBox error={error?.message} fields={error?.fields} />
      <div className="flex items-center gap-3">
        <button type="submit" className={btnCls} disabled={pending || !check?.ok}>{pending ? "Emitiendo…" : isCredit ? "Emitir nota de crédito" : "Emitir comprobante"}</button>
        <a href={back} className="text-sm text-slate-600 hover:underline">Cancelar</a>
      </div>
    </form>
  );
}

type Provider = { id: string; ref: string; name: string; status: string };

/** Registro de costo adicional (PROC-045) con impacto en el margen antes de guardar (FIN-BR-23). */
export function CostForm({ providers, costTypes, reservations, reservation, back }: {
  providers: Provider[]; costTypes: Record<string, string>; reservations: { code: string; title: string }[]; reservation?: string; back: string;
}) {
  const router = useRouter();
  const [res, setRes] = useState(reservation ?? "");
  const [amount, setAmount] = useState("");
  const [preview, setPreview] = useState<{ before: Figures; after: Figures; amount_pen: string } | null>(null);
  const [error, setError] = useState<{ message: string; fields?: Record<string, string[]> } | null>(null);
  const [pending, start] = useTransition();
  const idem = useRef(crypto.randomUUID());

  useEffect(() => {
    if (!res || !Number(amount)) return;
    const t = setTimeout(async () => {
      const r = await callApi("/finance/costs/preview", "POST", { reservation_id: res, amount });
      setPreview(r.ok ? (r.data as typeof preview) : null);
    }, 300);
    return () => clearTimeout(t);
  }, [res, amount]);

  function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.currentTarget).entries());
    start(async () => {
      const r = await callApi("/finance/costs", "POST", body, { "Idempotency-Key": idem.current });
      if (!r.ok) {
        setError({ message: r.error ?? "No se pudo registrar", fields: r.fieldErrors });
        if (r.code) idem.current = crypto.randomUUID();
        return;
      }
      router.push(`/finanzas/cuentas-por-pagar/${(r.data as { code: string }).code}`);
      router.refresh();
    });
  }

  return (
    <form onSubmit={submit} className="space-y-3" aria-label="Registrar costo">
      <Field label="Reserva">
        <input aria-label="Reserva" name="reservation_id" list="cost-reservations" value={res} onChange={(e) => setRes(e.target.value.toUpperCase())} required className={inputCls} placeholder="RES-2026-000000" />
        <datalist id="cost-reservations">{reservations.map((r) => <option key={r.code} value={r.code}>{r.title}</option>)}</datalist>
      </Field>
      <Field label="Proveedor">
        <select aria-label="Proveedor" name="provider_id" required className={inputCls} defaultValue="">
          <option value="">Seleccione…</option>
          {providers.filter((p) => p.status === "activo").map((p) => <option key={p.id} value={p.id}>{p.name} ({p.ref})</option>)}
        </select>
      </Field>
      <Field label="Tipo de costo">
        <select aria-label="Tipo de costo" name="cost_type" required className={inputCls} defaultValue="">
          <option value="">Seleccione…</option>
          {Object.entries(costTypes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </Field>
      <Field label="Concepto"><input name="concept" required maxLength={255} className={inputCls} /></Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Importe"><input name="amount" type="number" step="0.01" min="0.01" required value={amount} onChange={(e) => setAmount(e.target.value)} className={inputCls} /></Field>
        <Field label="Moneda"><select aria-label="Moneda" name="currency" className={inputCls} defaultValue=""><option value="">La de la reserva</option><option value="PEN">PEN</option><option value="USD">USD</option></select></Field>
      </div>
      <Field label="Documento del proveedor (opcional)"><input name="supplier_doc_number" maxLength={40} className={inputCls} /></Field>
      <Field label="Sustento / evidencia"><input name="evidence_note" maxLength={500} className={inputCls} /></Field>
      {preview && (
        <section className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm" aria-label="Impacto en rentabilidad">
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Impacto en rentabilidad</p>
          <p>Costo total: {money(preview.before.cost)} → <span className="font-medium">{money(preview.after.cost)}</span></p>
          <p>Margen: {preview.before.margin_pct.toLocaleString("es-PE")} % → <span className="font-medium">{preview.after.margin_pct.toLocaleString("es-PE")} %</span></p>
        </section>
      )}
      <ErrorBox error={error?.message} fields={error?.fields} />
      <div className="flex items-center gap-3">
        <button type="submit" className={btnCls} disabled={pending}>{pending ? "Registrando…" : "Registrar costo"}</button>
        <a href={back} className="text-sm text-slate-600 hover:underline">Cancelar</a>
      </div>
    </form>
  );
}

/** Importación del extracto (CSV/TXT ≤ 5 MB); sin archivo, el back-office genera un extracto simulado del banco elegido. */
export function StatementImport({ banks, accounts }: { banks: string[]; accounts: { id: string; bank: string; label: string; currency: string }[] }) {
  const router = useRouter();
  const [bank, setBank] = useState(banks[0] ?? "BCP");
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const [pending, start] = useTransition();
  const idem = useRef(crypto.randomUUID());

  function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    const file = data.get("file");
    start(async () => {
      const upload = file instanceof File && file.size > 0 ? await fileToUpload(file) : null;
      const r = await callApi("/finance/bank-statements", "POST", {
        bank, bank_account_id: data.get("bank_account_id") || null, file: upload ? { file_name: upload.file_name, content_base64: upload.content_base64 } : null,
      }, { "Idempotency-Key": idem.current });
      idem.current = crypto.randomUUID();
      if (!r.ok) return setMessage({ ok: false, text: [r.error, ...Object.values(r.fieldErrors ?? {}).flat()].join(" · ") });
      const d = r.data as { filename: string; lines: number };
      setMessage({ ok: true, text: `Extracto ${d.filename} importado: ${d.lines} líneas.` });
      router.refresh();
    });
  }

  return (
    <form onSubmit={submit} className="space-y-3" aria-label="Importar extracto">
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Banco">
          <select aria-label="Banco" name="bank" value={bank} onChange={(e) => setBank(e.target.value)} className={inputCls}>{banks.map((b) => <option key={b}>{b}</option>)}</select>
        </Field>
        <Field label="Cuenta">
          <select aria-label="Cuenta" name="bank_account_id" className={inputCls} defaultValue="">
            <option value="">—</option>{accounts.filter((a) => a.bank === bank).map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
          </select>
        </Field>
        <Field label="Archivo (CSV/TXT)" hint="Opcional: sin archivo se usa un extracto simulado.">
          <input name="file" type="file" accept=".csv,.txt,text/csv,text/plain" className="block w-full text-sm" />
        </Field>
      </div>
      <button type="submit" className={btnCls} disabled={pending}>{pending ? "Importando…" : "Importar extracto"}</button>
      {message && <p className={`rounded-md px-3 py-2 text-sm ring-1 ${message.ok ? "bg-emerald-50 text-emerald-800 ring-emerald-200" : "bg-rose-50 text-rose-800 ring-rose-200"}`}>{message.text}</p>}
    </form>
  );
}

/** Botón de impresión de la vista previa del comprobante. */
export function PrintButton() {
  return <button type="button" onClick={() => window.print()} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 print:hidden">Imprimir / PDF</button>;
}
