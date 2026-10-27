import type { CmsDetail } from "@/lib/channels";
import { ActionForm } from "@/components/crm/ActionForm";
import { Input, Select, TextArea } from "@/components/crm/ui";

/** Formulario de contenido: alta (POST, queda en borrador) o edición (PATCH con If-Match). */
export function CmsForm({ content, types, channels, disabled }: { content?: CmsDetail; types: string[]; channels: string[]; disabled?: string }) {
  return (
    <ActionForm path={content ? `/cms/contents/${content.code}` : "/cms/contents"} method={content ? "PATCH" : "POST"} ifMatch={content?.lock_version}
      submitLabel={content ? "Guardar" : "Guardar borrador"} redirectTo={content ? undefined : "/canales/cms/{data.code}"} success={content ? "Cambios guardados." : undefined}
      disabled={!!disabled} disabledReason={disabled}>
      <Input name="title" label="Título" required maxLength={150} defaultValue={content?.title} />
      <div className="grid grid-cols-2 gap-3">
        <Select name="type" label="Tipo" required placeholder="Elegir…" options={types.map((t) => ({ value: t, label: t }))} defaultValue={content?.type} />
        <Select name="channel" label="Canal destino" required placeholder="Elegir…" options={channels.map((t) => ({ value: t, label: t }))} defaultValue={content?.channel} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Input name="slug" label="URL" placeholder="Se genera del título" defaultValue={content?.slug} maxLength={120} />
        <Input name="placement" label="Ubicación (banners)" placeholder="shop_top, portal_home…" defaultValue={content?.placement ?? ""} maxLength={40} />
      </div>
      <TextArea name="body" label="Contenido" rows={10} defaultValue={content?.body}
        placeholder={"## Subtítulo\nPárrafo de texto.\n- Viñeta"} />
      <p className="text-xs text-slate-500">Formato simple: líneas con &quot;## &quot; son subtítulos y con &quot;- &quot; viñetas. No se admite HTML.</p>
      <Input name="seo_title" label="Título SEO (hasta 60)" maxLength={60} defaultValue={content?.seo_title ?? ""} />
      <TextArea name="seo_description" label="Meta descripción (hasta 160)" maxLength={160} rows={2} defaultValue={content?.seo_description ?? ""} />
      <div className="grid grid-cols-2 gap-3">
        <Input name="valid_from" type="date" label="Vigente desde" defaultValue={content?.valid_from ?? ""} />
        <Input name="valid_to" type="date" label="Vigente hasta" defaultValue={content?.valid_to ?? ""} />
      </div>
    </ActionForm>
  );
}
