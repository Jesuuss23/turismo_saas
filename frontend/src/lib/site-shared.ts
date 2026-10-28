/** Tipos, formatos y textos del sitio público (spec 15), compartidos entre servidor y cliente. */

export type Locale = "es" | "en";
export type Currency = "PEN" | "USD";

export type SiteInfo = {
  name: string;
  legal_name: string;
  ruc: string;
  fiscal_address: string;
  whatsapp: string;
  phone: string;
  emergency: string;
  email: string | null;
  hours: string;
  socials: string[];
  payment_methods: string[];
  sedes: { code: string; name: string; city: string | null; address: string | null; phone: string | null }[];
  fx: { usd_pen: string; date: string; source: string };
  hold_minutes: number;
  banner: { title: string; body: string; slug: string } | null;
};

export type ProductCard = {
  id: string;
  ref: string;
  slug: string;
  name: string;
  summary: string | null;
  translated: boolean;
  destination: string | null;
  type: string | null;
  category: string | null;
  duration: string;
  duration_days: number;
  modality: string | null;
  languages: string[];
  difficulty: string | null;
  difficulty_label: string | null;
  featured: boolean;
  rating: { avg: string; count: number; demo: boolean; verified?: boolean } | null;
  currency: Currency;
  from_price: string | null;
  list_price: string | null;
  rate_currency: string;
  tags: string[];
  sold: number;
  next_departure: { date: string; time: string; available: number } | null;
  daily: boolean;
};

export type Departure = { id: string; ref: string; date: string; time: string; capacity: number; sold: number; available: number; guaranteed: boolean; meeting_point: string | null };

export type ProductDetail = ProductCard & {
  description: string | null;
  min_pax: number;
  max_pax: number;
  min_age: number;
  advance_min_days: number;
  advance_max_days: number;
  by_departure: boolean;
  children_allowed: boolean;
  infants_allowed: boolean;
  pickup: string | null;
  meeting_point: string | null;
  gallery: { alt: string; tone: string }[];
  route: string[];
  itinerary: { day: number; title: string; items: { time: string | null; activity: string }[] }[];
  includes: string[];
  excludes: string[];
  requirements: string[];
  policies: Record<string, string>;
  faqs: { q: string; a: string }[];
  config: { id: string; name: string; options: { id: string; label: string; delta_pp: string }[] }[];
  extras: { code: string; label: string; price: string }[];
  departures: Departure[];
  promotions: { name: string; label: string; code: string | null; min_pax: number | null; ends_at: string | null }[];
  related: ProductCard[];
  seo: { title: string; description: string | null };
  json_ld: Record<string, unknown>;
};

export type CalendarDay = {
  date: string;
  sellable: boolean;
  reason: string | null;
  departure: { id: string; ref: string; time: string; guaranteed: boolean } | null;
  available: number | null;
  price: string | null;
  season: string | null;
  season_name: string | null;
};

export type Quote = {
  currency: Currency;
  unit_adult: string;
  unit_child: string;
  units: number | null;
  subtotal: string;
  discount: string;
  discount_label: string | null;
  extras: { code: string; label: string; amount: string }[];
  extras_total: string;
  total: string;
  list_price: string | null;
  season: { name: string; kind: string } | null;
  availability: { sellable: boolean; reason: string | null; available: number | null };
};

export type CartLine = {
  line: number;
  code: string;
  product: { slug: string; name: string; destination: string | null; type: string | null; min_age: number };
  date: string;
  time: string | null;
  departure: string | null;
  adults: number;
  children: number;
  infants: number;
  options: { group: string; option: string; delta_pp: string }[];
  extras: { code: string; label: string; amount: string; currency: string }[];
  season: { name: string; kind: string } | null;
  unit_adult: string;
  unit_child: string;
  subtotal: string;
  discount: string;
  discount_label: string | null;
  extras_total: string;
  total: string;
  hold: { ref: string | null; status: string; expires_at: string | null } | null;
  on_request: boolean;
};

export type PayMode = { mode: "total" | "anticipo"; pct: number; amount: string; available: boolean; reason: string | null; balance_due: string | null };

export type Cart = {
  code: string;
  status: string;
  currency: Currency;
  promo_code: string | null;
  promo_message: string | null;
  promo_applied: boolean;
  lines: CartLine[];
  count: number;
  subtotal: string;
  discount: string;
  extras: string;
  total: string;
  hold_expires_at: string | null;
  first_date: string | null;
  pay_modes: PayMode[];
  note: string;
};

export type Order = {
  code: string;
  status: string;
  status_label: string;
  ordered_at: string;
  buyer: { name: string; email: string };
  currency: Currency;
  pay_mode: "total" | "anticipo";
  deposit_pct: string;
  total: string;
  discount: string;
  amount_due_now: string;
  paid: string;
  pending_validation: string;
  balance: string;
  balance_due_date: string | null;
  billing: { type: string; doc_type: string; doc_number: string; name: string; fiscal_address: string | null } | null;
  hold_expires_at: string | null;
  promo_code: string | null;
  lines: {
    line: number;
    product: string;
    slug: string;
    date: string;
    time: string | null;
    meeting_point: string | null;
    adults: number;
    children: number;
    infants: number;
    extras: { code: string; label: string; amount: string; currency: string }[];
    total: string;
    options: { group: string; option: string; delta_pp: string }[];
    reservation: { code: string; status: string; status_label: string } | null;
    services: { code: string; name: string; status: string; status_label: string; confirmation_code: string | null }[];
  }[];
  receivables: { code: string; concept: string; kind: string; amount: string; paid: string; balance: string; due_date: string; status: string; status_label: string }[];
  vouchers: { code: string; service: string; date: string; reservation: string | null; pax: number; version: number; status_label?: string }[];
  passengers: { name: string; type: string }[];
  attempts: { at: string; method: string; result: string; message: string | null; card: string | null; operation_ref: string | null }[];
  next_steps: { label: string; done: boolean }[];
};

export type Customer = {
  name: string;
  email: string;
  phone: string | null;
  client: { code: string; name: string; doc_type: string | null; doc_number: string | null; country: string | null; email: string | null };
  newsletter: boolean;
  lock_version: number;
  portal_url: string;
};

export const money = (amount: string | number | null | undefined, currency: string) => {
  const n = Number(amount ?? 0);
  const v = n.toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `${currency === "USD" ? "US$" : "S/"} ${v}`;
};

export const fmtDate = (iso: string | null | undefined, locale: Locale = "es", opts: Intl.DateTimeFormatOptions = { day: "2-digit", month: "short", year: "numeric" }) =>
  iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString(locale === "en" ? "en-US" : "es-PE", opts) : "—";

export const TYPE_ROUTES: Record<string, { es: string; en: string }> = {
  tours: { es: "Tours", en: "Tours" },
  paquetes: { es: "Paquetes", en: "Packages" },
  experiencias: { es: "Experiencias", en: "Experiences" },
};

export const REASONS: Record<string, { es: string; en: string }> = {
  pasado: { es: "Fecha pasada", en: "Past date" },
  fuera_de_plazo: { es: "Fuera del plazo de reserva", en: "Outside the booking window" },
  sin_tarifa: { es: "Tarifa por publicar", en: "Rate not yet published" },
  bajo_solicitud: { es: "Confirmación en 24 h", en: "Confirmed within 24 h" },
  sin_salida: { es: "Sin salida programada", en: "No departure" },
  agotado: { es: "Agotado", en: "Sold out" },
  sin_cupo_para_el_grupo: { es: "Sin cupo para su grupo", en: "Not enough seats for your group" },
};

const DICT = {
  es: {
    destinations: "Destinos", tours: "Tours", packages: "Paquetes", experiences: "Experiencias", offers: "Ofertas", tailor: "Viajes a medida", blog: "Blog",
    contact: "Contacto", about: "Nosotros", cart: "Carrito", account: "Mi cuenta", signin: "Ingresar", search: "Buscar", from: "Desde", perPerson: "por persona",
    addToCart: "Añadir al carrito", bookNow: "Reservar ahora", seeTour: "Ver tour", nextDeparture: "Próxima salida", daily: "Todos los días · confirmación en 24 h",
    guaranteed: "Salida garantizada", seats: "cupos", adults: "Adultos", children: "Niños (2–11)", infants: "Infantes (0–1)", date: "Fecha", travelers: "Viajeros",
    total: "Total", subtotal: "Subtotal", discount: "Descuento", extras: "Extras", checkout: "Ir al checkout", emptyCart: "Su carrito está vacío.",
    newsletter: "Reciba ofertas y guías de viaje", subscribe: "Suscribirme", legal: "Legal", claimBook: "Libro de Reclamaciones", fxToday: "TC del día",
    whyUs: "Por qué elegirnos", featured: "Tours y paquetes destacados", guaranteedTitle: "Salidas grupales garantizadas", reviews: "Lo que dicen nuestros viajeros",
    heroTitle: "Viva los Andes con operación propia", heroText: "Machu Picchu, Valle Sagrado, Humantay y más: precios por temporada, cupos en tiempo real y pago seguro.",
    whereTo: "¿A dónde quiere ir?", any: "Cualquier destino", results: "resultados", noResults: "No encontramos tours con esos filtros.", clear: "Limpiar filtros",
    itinerary: "Itinerario", includes: "Incluye", excludes: "No incluye", requirements: "Requisitos", policies: "Políticas", faq: "Preguntas frecuentes",
    departures: "Próximas salidas", related: "También le puede interesar", meeting: "Punto de encuentro", chooseDate: "Elija una fecha", whatsapp: "Consultar por WhatsApp",
    quoteCustom: "Cotizar a medida", insurance: "Seguro de asistencia", transfer: "Traslado privado aeropuerto → hotel", demo: "Reseñas de ejemplo (demo)",
  },
  en: {
    destinations: "Destinations", tours: "Tours", packages: "Packages", experiences: "Experiences", offers: "Deals", tailor: "Tailor-made trips", blog: "Blog",
    contact: "Contact", about: "About us", cart: "Cart", account: "My account", signin: "Sign in", search: "Search", from: "From", perPerson: "per person",
    addToCart: "Add to cart", bookNow: "Book now", seeTour: "View tour", nextDeparture: "Next departure", daily: "Every day · confirmed within 24 h",
    guaranteed: "Guaranteed departure", seats: "seats", adults: "Adults", children: "Children (2–11)", infants: "Infants (0–1)", date: "Date", travelers: "Travelers",
    total: "Total", subtotal: "Subtotal", discount: "Discount", extras: "Extras", checkout: "Go to checkout", emptyCart: "Your cart is empty.",
    newsletter: "Get deals and travel guides", subscribe: "Subscribe", legal: "Legal", claimBook: "Complaints Book", fxToday: "Today's FX",
    whyUs: "Why travel with us", featured: "Featured tours & packages", guaranteedTitle: "Guaranteed group departures", reviews: "What our travelers say",
    heroTitle: "Experience the Andes with a local operator", heroText: "Machu Picchu, Sacred Valley, Humantay and more: seasonal prices, real-time seats and secure payment.",
    whereTo: "Where do you want to go?", any: "Any destination", results: "results", noResults: "No tours match those filters.", clear: "Clear filters",
    itinerary: "Itinerary", includes: "Included", excludes: "Not included", requirements: "Requirements", policies: "Policies", faq: "FAQ",
    departures: "Upcoming departures", related: "You may also like", meeting: "Meeting point", chooseDate: "Choose a date", whatsapp: "Ask on WhatsApp",
    quoteCustom: "Request a custom quote", insurance: "Travel assistance insurance", transfer: "Private airport → hotel transfer", demo: "Sample reviews (demo)",
  },
} as const;

export type Dict = { [K in keyof (typeof DICT)["es"]]: string };
export const t = (locale: Locale): Dict => DICT[locale];
