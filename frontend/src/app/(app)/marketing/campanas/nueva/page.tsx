import { api } from "@/lib/api";
import { PageHeader } from "@/components/crm/ui";
import { BackLink } from "@/components/marketing/ui";
import { CampaignEditor } from "@/components/marketing/CampaignEditor";
import type { Options, Segment } from "@/lib/marketing";

export default async function NewCampaignPage({ searchParams }: { searchParams: Promise<{ segment?: string }> }) {
  const { segment } = await searchParams;
  const [options, segments] = await Promise.all([api<{ data: Options }>("/marketing/options"), api<{ data: Segment[] }>("/marketing/segments")]);
  return (
    <>
      <BackLink href="/marketing/campanas">Campañas</BackLink>
      <PageHeader title="Nueva campaña" subtitle="Se crea en borrador; antes de programar o enviar exige una prueba a un usuario interno (MKT-BR-04)." />
      <CampaignEditor campaign={null} options={options.data} segments={segments.data} defaultSegment={segment} />
    </>
  );
}
