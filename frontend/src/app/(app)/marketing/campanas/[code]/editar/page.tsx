import { notFound } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { Notice, PageHeader } from "@/components/crm/ui";
import { BackLink } from "@/components/marketing/ui";
import { CampaignEditor } from "@/components/marketing/CampaignEditor";
import type { Campaign, Options, Segment } from "@/lib/marketing";

export default async function EditCampaignPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  let campaign: Campaign;
  try {
    campaign = (await api<{ data: Campaign }>(`/marketing/campaigns/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound();
    throw e;
  }
  const [options, segments] = await Promise.all([api<{ data: Options }>("/marketing/options"), api<{ data: Segment[] }>("/marketing/segments")]);
  return (
    <>
      <BackLink href={`/marketing/campanas/${campaign.code}`}>{campaign.code}</BackLink>
      <PageHeader title={`Editar ${campaign.code}`} subtitle="Editar el contenido invalida las pruebas enviadas (MKT-BR-04)." />
      {campaign.can.edit ? <CampaignEditor campaign={campaign} options={options.data} segments={segments.data} />
        : <Notice tone="warning">Solo se editan campañas en borrador (estado actual: {campaign.status_label}).</Notice>}
    </>
  );
}
