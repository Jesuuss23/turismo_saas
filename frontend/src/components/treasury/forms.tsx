"use client";

import { useMemo, useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, inputCls } from "@/components/crm/ui";
import { fileToUpload } from "@/lib/ops";
import { amount, type AccountOption } from "@/lib/treasury";

function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: React.ReactNode }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

type Result = { error?: string; fields?: Record<string, string[]>; info?: string } | null;

function Message({ result }: { result: Result }) {
  if (!result) return null;
  if (result.error) {
    return (
      <p role="alert" className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">
        {result.error}{result.fields && <span className="mt-1 block text-xs">{Object.values(result.fields).flat().join(" · ")}</span>}
      </p>
    );
  }
  return <p className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{result.info}</p>;
}

/** Envío JSON con Idempotency-Key estable hasta que la API responda (doble clic = misma operación). */
function useSubmit(path: string) {
  const router = useRouter();
  const [result, setResult] = useState<Result>(null);
  const [pending, start] = useTransition();
  const idem = useRef(crypto.randomUUID());
  function submit(body: unknown, onOk?: () => void) {
    start(async () => {
      const res = await callApi(path, "POST", body, { "Idempotency-Key": idem.current });
      if (res.ok || res.code) idem.current = crypto.randomUUID();
      if (!res.ok) {
        setResult({ error: res.error ?? "No se pudo registrar", fields: res.fieldErrors });
        return;
      }
      setResult({ info: res.info ?? "Registrado" });
      onOk?.();
      router.refresh();
    });
  }
  return { result, pending, submit };
}

/** Transferencia entre cuentas o depósito de efectivo (TES-BR-02); con monedas distintas pide el TC pactado. */
export function TransferForm({ accounts, from, today, deposit = false }: { accounts: AccountOption[]; from?: string; today: string; deposit?: boolean }) {
  const usable = accounts.filter((a) => a.kind !== "gateway");
  const [src, setSrc] = useState(from ?? usable[0]?.id ?? "");
  const targets = usable.filter((a) => a.id !== src && (!deposit || a.kind === "bank"));
  const [dst, setDst] = useState(targets[0]?.id ?? "");
  const [value, setValue] = useState("");
  const [rate, setRate] = useState("");
  const a = usable.find((x) => x.id === src);
  const b = usable.find((x) => x.id === dst) ?? targets[0];
  const fx = a && b && a.currency !== b.currency;
  const received = fx && Number(value) > 0 && Number(rate) > 0 ? (a.currency === "USD" ? Number(value) * Number(rate) : Number(value) / Number(rate)) : null;
  const { result, pending, submit } = useSubmit("/treasury/transfers");

  return (
    <form className="space-y-3" aria-label={deposit ? "Depositar efectivo" : "Transferencia entre cuentas"} onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      submit({ kind: deposit ? "cash_deposit" : "transfer", from_account_id: src, to_account_id: b?.id, amount_from: value, deal_rate: fx ? rate : null,
        transfer_date: f.get("transfer_date"), reference: f.get("reference"), description: f.get("description") }, () => setValue(""));
    }}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Cuenta origen">
          <select aria-label="Cuenta origen" value={src} onChange={(e) => setSrc(e.target.value)} className={inputCls} disabled={!!from}>
            {usable.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name} ({x.currency})</option>)}
          </select>
        </Field>
        <Field label="Cuenta destino">
          <select aria-label="Cuenta destino" value={b?.id ?? ""} onChange={(e) => setDst(e.target.value)} className={inputCls}>
            {targets.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name} ({x.currency})</option>)}
          </select>
        </Field>
        <Field label={`Monto (${a?.currency ?? "PEN"})`}><input aria-label="Monto" type="number" step="0.01" min="0.01" value={value} onChange={(e) => setValue(e.target.value)} required className={inputCls} /></Field>
        {fx && (
          <Field label="Tipo de cambio pactado" hint={received !== null ? `Se acreditan ${amount(received, b.currency)}` : "Obligatorio entre monedas distintas"}>
            <input aria-label="Tipo de cambio pactado" type="number" step="0.0001" min="0" value={rate} onChange={(e) => setRate(e.target.value)} required className={inputCls} />
          </Field>
        )}
        <Field label="Fecha"><input aria-label="Fecha" name="transfer_date" type="date" defaultValue={today} max={today} required className={inputCls} /></Field>
        <Field label="N° de operación"><input aria-label="N° de operación" name="reference" required maxLength={80} className={inputCls} /></Field>
      </div>
      <Field label="Glosa (opcional)"><input name="description" maxLength={160} className={inputCls} /></Field>
      <button className={btnCls} disabled={pending || !b}>{pending ? "Registrando…" : deposit ? "Registrar depósito" : "Registrar transferencia"}</button>
      <Message result={result} />
    </form>
  );
}

/** Gasto de caja chica con comprobante (TES-BR-11/12): RUC en factura y RHE; declaración jurada con tope. */
export function ExpenseForm({ account, receiptTypes, max, swornMax, today }: { account: { id: string; code: string; name: string; balance: number }; receiptTypes: Record<string, string>; max: string; swornMax: string; today: string }) {
  const [type, setType] = useState("ticket");
  const [file, setFile] = useState<File | null>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const { result, pending, submit } = useSubmit(`/treasury/petty-cash/${account.code}/expenses`);
  const needsSeries = !["mobility_sheet", "sworn_statement"].includes(type);
  const needsRuc = ["invoice", "fee_receipt"].includes(type);

  return (
    <form ref={formRef} className="space-y-3" aria-label={`Registrar gasto en ${account.name}`} onSubmit={async (e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      const upload = file ? await fileToUpload(file) : null;
      submit({ receipt_type: type, series_number: f.get("series_number") || null, issuer_ruc: f.get("issuer_ruc") || null, supplier_name: f.get("supplier_name"),
        description: f.get("description"), amount: f.get("amount"), category: f.get("category") || null, movement_date: f.get("movement_date"),
        reservation_code: f.get("reservation_code") || null, ...(upload ?? {}) }, () => { formRef.current?.reset(); setFile(null); });
    }}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Comprobante">
          <select aria-label="Comprobante" value={type} onChange={(e) => setType(e.target.value)} className={inputCls}>
            {Object.entries(receiptTypes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Field>
        <Field label="Monto (PEN)" hint={`Tope S/ ${max}${type === "sworn_statement" ? ` · declaración jurada hasta S/ ${swornMax}` : ""} · saldo ${amount(account.balance)}`}>
          <input aria-label="Monto del gasto" name="amount" type="number" step="0.01" min="0.01" required className={inputCls} />
        </Field>
        {needsSeries && <Field label="Serie y número"><input aria-label="Serie y número" name="series_number" required maxLength={30} placeholder="B001-000123" className={inputCls} /></Field>}
        {needsRuc && <Field label="RUC del emisor"><input aria-label="RUC del emisor" name="issuer_ruc" required pattern="\d{11}" maxLength={11} className={inputCls} /></Field>}
        <Field label="Proveedor o beneficiario"><input aria-label="Proveedor o beneficiario" name="supplier_name" required maxLength={160} className={inputCls} /></Field>
        <Field label="Categoría (opcional)"><input name="category" maxLength={60} placeholder="Movilidad, útiles…" className={inputCls} /></Field>
        <Field label="Fecha"><input name="movement_date" type="date" defaultValue={today} max={today} required className={inputCls} /></Field>
        <Field label="Reserva (opcional)"><input name="reservation_code" maxLength={30} placeholder="RES-2026-000123" className={inputCls} /></Field>
      </div>
      <Field label="Descripción"><input aria-label="Descripción del gasto" name="description" required maxLength={255} className={inputCls} /></Field>
      <Field label="Archivo del comprobante (opcional · PDF/JPG/PNG)"><input type="file" accept="application/pdf,image/jpeg,image/png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" /></Field>
      <button className={btnCls} disabled={pending}>{pending ? "Registrando…" : "Registrar gasto"}</button>
      <Message result={result} />
    </form>
  );
}

/** Acta de arqueo por denominación (TES-BR-13): total en vivo, diferencia contra el saldo y observación obligatoria si no cuadra. */
export function CountForm({ account, denominations, expected, tolerance, witnesses, closing }: {
  account: { code: string; name: string }; denominations: string[]; expected: number; tolerance: number; witnesses: { id: string; name: string }[]; closing: boolean;
}) {
  const [qty, setQty] = useState<Record<string, string>>({});
  const counted = useMemo(() => Math.round(denominations.reduce((s, d) => s + Number(d) * (Number(qty[d]) || 0), 0) * 100) / 100, [qty, denominations]);
  const diff = Math.round((counted - expected) * 100) / 100;
  const { result, pending, submit } = useSubmit(`/treasury/petty-cash/${account.code}/counts`);

  return (
    <form className="space-y-3" aria-label={closing ? `Cerrar ${account.name}` : `Arqueo de ${account.name}`} onSubmit={(e) => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      submit({ kind: closing ? "closing" : "surprise", denominations: Object.fromEntries(Object.entries(qty).filter(([, v]) => v !== "")), note: f.get("note") || null, witness_id: f.get("witness_id") || null },
        () => setQty({}));
    }}>
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
        {denominations.map((d) => (
          <label key={d} className="text-xs">
            <span className="mb-1 block text-slate-600">S/ {d}</span>
            <input aria-label={`Cantidad de S/ ${d}`} type="number" min="0" step="1" value={qty[d] ?? ""} onChange={(e) => setQty((q) => ({ ...q, [d]: e.target.value }))} className={inputCls} />
          </label>
        ))}
      </div>
      <p className="text-sm" data-testid="count-summary">
        Contado <strong>{amount(counted)}</strong> · esperado {amount(expected)} · diferencia{" "}
        <strong className={diff === 0 ? "text-emerald-700" : Math.abs(diff) > tolerance ? "text-rose-700" : "text-amber-700"}>{amount(diff)}</strong>
        {Math.abs(diff) > tolerance && <span className="block text-xs text-rose-700">Supera la tolerancia de {amount(tolerance)}: quedará pendiente de aprobación (TES-BR-14).</span>}
      </p>
      {diff !== 0 && <Field label="Observación (obligatoria si no cuadra)"><textarea aria-label="Observación" name="note" required rows={2} maxLength={2000} className={inputCls} /></Field>}
      <Field label="Testigo (opcional)">
        <select name="witness_id" className={inputCls} defaultValue="">
          <option value="">Sin testigo</option>
          {witnesses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
        </select>
      </Field>
      <button className={btnCls} disabled={pending || counted <= 0}>{pending ? "Firmando…" : closing ? "Firmar acta y cerrar caja" : "Firmar acta de arqueo"}</button>
      <Message result={result} />
    </form>
  );
}
