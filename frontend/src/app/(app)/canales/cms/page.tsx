import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import type { CmsRow } from "@/lib/channels";
import { Drawer, Kpis, PageHeader, Table, Td, btnGhostCls, inputCls } from "@/components/crm/ui";
import { ChStatus } from "@/components/channels/ui";
import { withParams } from "@/components/supplier/ui";
import { CmsForm } from "./form";

type Meta = { counts: Record<string, number>; types: string[]; channels: string[]; statuses: Record<string, string>; can_publish: boolean };
type Sp = { q?: string; status?: string; type?: string; channel?: string; nuevo?: string };

/** CMS del sitio (spec 08 §2 `cms`, PROC-055): contenidos con flujo borrador → revisión → aprobado → publicado. */
export default async function CmsPage({ searchParams }: { searchParams: Promise<Sp> }) {
  const sp = await searchParams;
  const qs = new URLSearchParams();
  if (sp.q) qs.set("q", sp.q);
  for (const k of ["status", "type", "channel"] as const) if (sp[k]) qs.set(`filter[${k}]`, sp[k]!);
  const { data: rows, meta } = await api<{ data: CmsRow[]; meta: Meta }>(`/cms/contents?${qs}`);
  const base = `/canales/cms${qs.size ? `?${new URLSearchParams(Object.entries(sp).filter(([k, v]) => k !== "nuevo" && v) as [string, string][])}` : ""}`;

  return (
    <>
      <PageHeader title="CMS del sitio" subtitle={meta.can_publish ? "Redacte, revise y publique el contenido del sitio y los portales." : "Redacte contenido y envíelo a revisión; un editor lo publica."}
        actions={<Link href={withParams(base, { nuevo: "1" })} scroll={false} className="rounded-md bg-sky-700 px-3 py-2 text-sm font-medium text-white hover:bg-sky-800">Nuevo contenido</Link>} />
      <Kpis items={Object.entries(meta.statuses).filter(([k]) => k !== "despublicado").map(([k, label]) => ({ label, value: meta.counts[k] ?? 0 }))} />
      <form className="my-4 flex flex-wrap gap-2" action="/canales/cms">
        <input name="q" defaultValue={sp.q} placeholder="Título o URL" aria-label="Buscar contenido" className={`${inputCls} max-w-xs`} />
        <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className={`${inputCls} max-w-[11rem]`}>
          <option value="">Todos los estados</option>
          {Object.entries(meta.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <select name="type" defaultValue={sp.type ?? ""} aria-label="Tipo" className={`${inputCls} max-w-[11rem]`}>
          <option value="">Todos los tipos</option>
          {meta.types.map((t) => <option key={t}>{t}</option>)}
        </select>
        <select name="channel" defaultValue={sp.channel ?? ""} aria-label="Canal" className={`${inputCls} max-w-[12rem]`}>
          <option value="">Todos los canales</option>
          {meta.channels.map((t) => <option key={t}>{t}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Contenido", "Tipo", "Canal", "URL", "Autor", "Actualizado", "Estado"]} empty="No hay contenidos con esos filtros">
        {rows.map((c) => (
          <tr key={c.id}>
            <Td><Link href={`/canales/cms/${c.code}`} className="font-medium text-sky-800 hover:underline">{c.title}</Link><span className="block text-xs text-slate-500">{c.code}{c.placement ? ` · ubicación ${c.placement}` : ""}</span></Td>
            <Td>{c.type}</Td>
            <Td>{c.channel}</Td>
            <Td className="font-mono text-xs">/{c.slug}</Td>
            <Td>{c.author ?? "—"}</Td>
            <Td className="whitespace-nowrap text-xs">{dateTime(c.updated_at)}</Td>
            <Td><ChStatus value={c.status} label={c.status_label} /></Td>
          </tr>
        ))}
      </Table>
      {sp.nuevo && (
        <Drawer title="Nuevo contenido" close={base}>
          <CmsForm types={meta.types} channels={meta.channels} />
        </Drawer>
      )}
    </>
  );
}
