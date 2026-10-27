import Link from "@/components/Link";
import { tone, type Banner } from "@/lib/channels";

export function ChStatus({ value, label }: { value: string; label: string }) {
  return <span className={`inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${tone(value)}`}>{label}</span>;
}

/**
 * Marco "Vista del canal" (spec 08 §2): para internos envuelve el portal con una barra de solo lectura y su propia navegación;
 * para externos solo devuelve el cuerpo (el shell externo ya trae la navegación).
 */
export function ChannelFrame({ preview, label, nav, active, picker, children }: {
  preview: boolean; label: string; nav: { href: string; label: string; key: string }[]; active: string; picker?: React.ReactNode; children: React.ReactNode;
}) {
  if (!preview) return <>{children}</>;
  return (
    <div className="rounded-lg border border-indigo-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-t-lg bg-indigo-50 px-4 py-2 text-sm text-indigo-900">
        <p><span className="font-semibold">Vista del canal · {label}</span> · solo lectura: no marca vistas, no reserva ni paga.</p>
        {picker}
      </div>
      <nav className="flex flex-wrap gap-1 border-b border-slate-200 px-3 text-sm" aria-label={`Secciones de ${label}`}>
        {nav.map((n) => (
          <Link key={n.key} href={n.href} className={`-mb-px border-b-2 px-3 py-2 ${n.key === active ? "border-indigo-700 font-medium text-indigo-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
            {n.label}
          </Link>
        ))}
      </nav>
      <div className="p-4">{children}</div>
    </div>
  );
}

/** Selector GET del registro a previsualizar (agencia, cliente o reserva). */
export function PreviewPicker({ name, label, value, options, action }: { name: string; label: string; value: string; options: { value: string; label: string }[]; action: string }) {
  return (
    <form action={action} className="flex items-center gap-2">
      <label className="text-xs font-medium" htmlFor={`pick-${name}`}>{label}</label>
      <select id={`pick-${name}`} name={name} defaultValue={value} className="max-w-[14rem] rounded-md border border-indigo-200 bg-white px-2 py-1 text-xs">
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
      <button className="rounded-md bg-indigo-700 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-800">Ver</button>
    </form>
  );
}

/** Contenido CMS publicado (HTML ya saneado por el backend: solo h2, p, ul/li con texto escapado). */
export function CmsBanner({ banner }: { banner: Banner }) {
  return (
    <section className="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900" aria-label={banner.title}>
      <p className="font-semibold">{banner.title}</p>
      <div className="prose-sm mt-1 [&_h2]:font-semibold [&_li]:ml-4 [&_li]:list-disc" dangerouslySetInnerHTML={{ __html: banner.html }} />
    </section>
  );
}

export function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: React.ReactNode }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

export function Dl({ rows }: { rows: [string, React.ReactNode][] }) {
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
      {rows.map(([k, v]) => (
        <div key={k} className="contents">
          <dt className="text-slate-500">{k}</dt>
          <dd className="text-slate-900">{v ?? "—"}</dd>
        </div>
      ))}
    </dl>
  );
}
