import Link from "@/components/Link";

const TABS = [["admin", "Agencias"], ["precios", "Tarifas por agencia"], ["portal", "Vista del portal"]] as const;

/** Pestañas del canal B2B en el back-office. */
export function B2bTabs({ active }: { active: (typeof TABS)[number][0] }) {
  return (
    <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Canal B2B">
      {TABS.map(([key, label]) => (
        <Link key={key} href={`/canales/b2b/${key}${key === "portal" ? "/catalogo" : ""}`}
          className={`-mb-px border-b-2 px-3 py-2 ${key === active ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
          {label}
        </Link>
      ))}
    </nav>
  );
}
