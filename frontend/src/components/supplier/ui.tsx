import Link from "@/components/Link";
import { SUPPLIER_TONE } from "@/lib/supplier";
import { OPS_TONE } from "@/lib/ops";

export function SupStatus({ value, label }: { value: string; label: string }) {
  const tone = SUPPLIER_TONE[value] ?? OPS_TONE[value] ?? "bg-slate-100 text-slate-700 ring-slate-200";
  return <span className={`inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${tone}`}>{label}</span>;
}

/** Enlace de acción de una fila (abre un drawer controlado por la URL). */
export function RowAction({ href, children, primary = false }: { href: string; children: React.ReactNode; primary?: boolean }) {
  return (
    <Link href={href} scroll={false}
      className={`inline-flex whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium ring-1 ${primary ? "bg-sky-700 text-white ring-sky-700 hover:bg-sky-800" : "bg-white text-slate-700 ring-slate-300 hover:bg-slate-50"}`}>
      {children}
    </Link>
  );
}

/** Une parámetros a una URL base que puede traer query string. */
export function withParams(base: string, params: Record<string, string>) {
  const [path, query = ""] = base.split("?");
  const search = new URLSearchParams(query);
  for (const [k, v] of Object.entries(params)) search.set(k, v);
  return `${path}?${search.toString()}`;
}

export function Label({ children }: { children: React.ReactNode }) {
  return <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">{children}</span>;
}
