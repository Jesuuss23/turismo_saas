import Link from "@/components/Link";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import type { CmsDetail } from "@/lib/channels";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Notice, PageHeader, TextArea } from "@/components/crm/ui";
import { ChStatus } from "@/components/channels/ui";
import { CmsForm } from "../form";

type Preview = { html: string; serp: { title: string; url: string; description: string; title_length: number; description_length: number } };
type Meta = { types: string[]; channels: string[] };

const STEP: Record<string, { label: string; success: string }> = {
  submit: { label: "Enviar a revisión", success: "Enviado a revisión." },
  approve: { label: "Aprobar", success: "Contenido aprobado." },
  publish: { label: "Publicar", success: "Contenido publicado." },
};

/** Editor de contenido (spec 08 §2 `cms/:id`): datos, SEO, vista previa, acciones del flujo editorial e historial. */
export default async function CmsEditorPage({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<{ accion?: string }> }) {
  const [{ id }, sp] = await Promise.all([params, searchParams]);
  const [{ data: c }, { meta }] = await Promise.all([
    api<{ data: CmsDetail }>(`/cms/contents/${encodeURIComponent(id)}`),
    api<{ meta: Meta }>("/cms/contents?filter[status]=__none__"),
  ]);
  const { data: preview } = await api<{ data: Preview }>(`/cms/contents/${c.code}/preview`, { method: "POST", body: "{}" });
  const self = `/canales/cms/${c.code}`;
  const canSave = c.actions.includes("save");

  return (
    <>
      <PageHeader title={c.title} subtitle={<><Link href="/canales/cms" className="text-sky-800 hover:underline">CMS</Link> · {c.code} · {c.type} · {c.channel}</>}
        actions={<ChStatus value={c.status} label={c.status_label} />} />
      {c.notice && <Notice tone="warning">{c.notice}</Notice>}
      <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card title="Contenido">
          <CmsForm content={c} types={meta.types} channels={meta.channels} disabled={canSave ? undefined : c.notice ?? "No puede editar este contenido en su estado actual."} />
        </Card>
        <div className="space-y-4">
          <Card title="Flujo editorial">
            <div className="flex flex-wrap gap-2">
              {c.actions.filter((a) => STEP[a]).map((a) => (
                <ActionForm key={a} path={`/cms/contents/${c.code}/${a}`} ifMatch={c.lock_version} inline submitLabel={STEP[a].label} success={STEP[a].success}
                  submitClass="inline-flex rounded-md bg-sky-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-800" />
              ))}
              {c.actions.includes("return") && sp.accion !== "devolver" && <Link href={`${self}?accion=devolver`} className="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300 hover:bg-slate-50">Devolver al autor</Link>}
              {c.actions.includes("unpublish") && sp.accion !== "despublicar" && <Link href={`${self}?accion=despublicar`} className="rounded-md px-3 py-1.5 text-sm text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Despublicar</Link>}
              {c.actions.length === 0 && <p className="text-sm text-slate-500">Sin acciones disponibles para su rol.</p>}
            </div>
            {sp.accion === "devolver" && c.actions.includes("return") && (
              <ActionForm path={`/cms/contents/${c.code}/return`} ifMatch={c.lock_version} submitLabel="Devolver" redirectTo={self} className="mt-3 space-y-2">
                <TextArea name="reason" label="Observaciones para el autor" required maxLength={300} />
              </ActionForm>
            )}
            {sp.accion === "despublicar" && c.actions.includes("unpublish") && (
              <ActionForm path={`/cms/contents/${c.code}/unpublish`} ifMatch={c.lock_version} submitLabel="Despublicar" redirectTo={self} className="mt-3 space-y-2">
                <TextArea name="reason" label="Motivo" required maxLength={300} />
              </ActionForm>
            )}
            {c.published_at && <p className="mt-2 text-xs text-slate-500">Publicado {dateTime(c.published_at)}</p>}
          </Card>
          <Card title="Vista en buscadores">
            <p className="truncate text-sm text-emerald-800">{preview.serp.url}</p>
            <p className="text-base text-blue-800">{preview.serp.title}</p>
            <p className="text-sm text-slate-600">{preview.serp.description || "Sin meta descripción."}</p>
            <p className="mt-2 text-xs text-slate-500">Título {preview.serp.title_length}/60 · descripción {preview.serp.description_length}/160</p>
          </Card>
          <Card title="Vista previa">
            <div className="text-sm [&_h2]:mt-2 [&_h2]:font-semibold [&_li]:ml-4 [&_li]:list-disc [&_p]:mt-1" dangerouslySetInnerHTML={{ __html: preview.html }} />
          </Card>
          <Card title="Historial">
            <ol className="space-y-2 text-sm">
              {c.events.map((e, i) => (
                <li key={i}><span className="text-xs text-slate-500">{dateTime(e.at)} · {e.user ?? "Sistema"}</span><span className="block">{e.message}</span>{e.reason && <span className="block text-xs text-slate-600">“{e.reason}”</span>}</li>
              ))}
            </ol>
          </Card>
        </div>
      </div>
    </>
  );
}
