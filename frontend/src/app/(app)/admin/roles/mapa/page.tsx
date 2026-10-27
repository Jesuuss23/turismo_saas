import { api } from "@/lib/api";
import { PageHeader } from "@/components/crm/ui";
import { Check, RouteTabs } from "@/components/admin/ui";
import { ROLE_TABS } from "../shared";

type AccessMap = {
  screens: { route: string; label: string; group: string }[];
  roles: { id: number; name: string; display_name: string; scope: string; home: string; can: number; cannot: number; reach: string; cells: Record<string, boolean> }[];
};

/** Mapa de acceso (spec 09 §4.4): qué pantallas abre cada rol según sus permisos efectivos. */
export default async function AccessMapPage() {
  const { data } = await api<{ data: AccessMap }>("/iam/access-map");
  const groups = [...new Set(data.screens.map((s) => s.group))];

  return (
    <section>
      <PageHeader title="Roles y permisos" subtitle="Mapa de acceso calculado con los permisos vigentes de cada rol." />
      <RouteTabs base="/admin/roles" tabs={ROLE_TABS} active="mapa" />
      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="min-w-full text-xs">
          <thead className="bg-slate-50">
            <tr>
              <th className="sticky left-0 bg-slate-50 px-3 py-2 text-left font-medium text-slate-600">Pantalla</th>
              {data.roles.map((r) => <th key={r.id} className="px-2 py-2 text-center font-medium text-slate-600" title={`${r.reach} · inicio: /${r.home}`}>{r.display_name}<span className="block font-normal text-slate-400">{r.can}</span></th>)}
            </tr>
          </thead>
          <tbody>
            {groups.map((g) => [
              <tr key={g}><td colSpan={data.roles.length + 1} className="bg-slate-100 px-3 py-1 font-semibold text-slate-700">{g}</td></tr>,
              ...data.screens.filter((s) => s.group === g).map((s) => (
                <tr key={s.route} className="border-t border-slate-100">
                  <td className="sticky left-0 bg-white px-3 py-1.5 text-slate-800">{s.label}</td>
                  {data.roles.map((r) => <td key={r.id} className="text-center"><Check ok={r.cells[s.route]} /></td>)}
                </tr>
              )),
            ])}
          </tbody>
        </table>
      </div>
    </section>
  );
}
