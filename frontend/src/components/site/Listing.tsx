import Link from "next/link";
import { prefs, siteApi } from "@/lib/site";
import { t, type ProductCard as Card } from "@/lib/site-shared";
import { ProductCard } from "./ProductCard";
import { TrackOnView } from "./TrackOnView";

type Params = Record<string, string | undefined>;
type Facets = { categories: string[]; destinations: { slug: string; name: string }[] };

const KEYS = ["q", "destination", "date", "pax", "duration", "price_min", "price_max", "category", "difficulty", "language", "modality", "sort"] as const;

/** Listado con filtros en la URL (compartible) y orden (spec 15 §2 tours/paquetes/experiencias/buscar). */
export async function Listing({ type, params, title, base }: { type?: string; params: Params; title: string; base: string }) {
  const { locale, currency } = await prefs();
  const d = t(locale);
  const en = locale === "en";
  const query: Record<string, string> = {};
  for (const k of KEYS) if (params[k]) query[k] = params[k]!;
  if (type) query.type = type;
  const res = await siteApi<{ data: Card[]; meta: { count: number; today: string; facets: Facets } }>("products", { query });
  const sel = (name: string, label: string, options: [string, string][]) => (
    <label className="text-xs font-medium text-slate-600">{label}
      <select name={name} defaultValue={params[name] ?? ""} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm">
        <option value="">{en ? "All" : "Todos"}</option>
        {options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </select>
    </label>
  );
  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="text-2xl font-semibold">{title}</h1>
      <TrackOnView event={params.q || params.destination || params.date ? "search" : "view_item_list"} params={{ item_list_name: title, ...query, currency }} />
      <div className="mt-4 grid gap-6 lg:grid-cols-[260px_1fr]">
        <form action={base} className="grid h-fit grid-cols-2 gap-2 rounded-2xl border border-slate-200 bg-white p-4 lg:grid-cols-1" aria-label={en ? "Filters" : "Filtros"}>
          <label className="col-span-2 text-xs font-medium text-slate-600 lg:col-span-1">{en ? "Search" : "Buscar"}
            <input name="q" defaultValue={params.q ?? ""} className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
          </label>
          {sel("destination", d.destinations, res.meta.facets.destinations.map((x) => [x.slug, x.name]))}
          <label className="text-xs font-medium text-slate-600">{d.date}
            <input type="date" name="date" min={res.meta.today} defaultValue={params.date ?? ""} className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
          </label>
          <label className="text-xs font-medium text-slate-600">{d.travelers}
            <input type="number" name="pax" min={1} max={20} defaultValue={params.pax ?? ""} className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
          </label>
          {sel("duration", en ? "Duration" : "Duración", [["1", en ? "Up to 1 day" : "Hasta 1 día"], ["2-4", en ? "2–4 days" : "2–4 días"], ["5+", en ? "5+ days" : "5+ días"]])}
          <label className="text-xs font-medium text-slate-600">{en ? `Min price (${currency})` : `Precio mín. (${currency})`}
            <input type="number" name="price_min" min={0} defaultValue={params.price_min ?? ""} className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
          </label>
          <label className="text-xs font-medium text-slate-600">{en ? `Max price (${currency})` : `Precio máx. (${currency})`}
            <input type="number" name="price_max" min={0} defaultValue={params.price_max ?? ""} className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm" />
          </label>
          {sel("category", en ? "Category" : "Categoría", res.meta.facets.categories.map((c) => [c, c]))}
          {sel("difficulty", en ? "Fitness level" : "Condición física", [["facil", en ? "Easy" : "Fácil"], ["moderada", en ? "Moderate" : "Moderada"], ["exigente", en ? "Demanding" : "Exigente"]])}
          {sel("language", en ? "Guide language" : "Idioma del guía", [["ES", "Español"], ["EN", "English"], ["FR", "Français"]])}
          {sel("modality", en ? "Service" : "Servicio", [["Compartido", en ? "Shared" : "Compartido"], ["Privado", en ? "Private" : "Privado"]])}
          {sel("sort", en ? "Sort by" : "Ordenar por", [["price", en ? "Price ↑" : "Precio ↑"], ["-price", en ? "Price ↓" : "Precio ↓"], ["duration", en ? "Duration" : "Duración"], ["best_seller", en ? "Best sellers" : "Más vendidos"], ["rating", en ? "Top rated" : "Mejor valorados"]])}
          <button className="col-span-2 rounded-lg bg-[#0b1f3a] py-2 text-sm font-medium text-white lg:col-span-1">{en ? "Apply" : "Aplicar"}</button>
          <Link href={base} className="col-span-2 text-center text-xs text-sky-700 hover:underline lg:col-span-1">{d.clear}</Link>
        </form>
        <div className="min-w-0">
          <p className="text-sm text-slate-600" data-testid="result-count">{res.meta.count} {d.results}</p>
          {res.data.length === 0 ? (
            <div className="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
              <p>{d.noResults}</p>
              <p className="mt-3 flex flex-wrap justify-center gap-3 text-sm">
                <Link href={base} className="text-sky-700 hover:underline">{d.clear}</Link>
                <Link href={`/sitio/viajes-a-medida${params.destination ? `?destino=${params.destination}` : ""}`} className="text-sky-700 hover:underline">{d.quoteCustom}</Link>
              </p>
            </div>
          ) : (
            <div className="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{res.data.map((p) => <ProductCard key={p.id} p={p} locale={locale} />)}</div>
          )}
        </div>
      </div>
    </div>
  );
}
