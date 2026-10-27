import { redirect } from "next/navigation";

const TAB: Record<string, string> = { viajes: "viaje", itinerario: "itinerario", vouchers: "vouchers", alertas: "alertas", contacto: "soporte" };

/** El portal del pasajero comparte pantallas con la app del pasajero (spec 05 §6.8): cada sección abre la pestaña equivalente. */
export default async function PassengerPortalPage({ params }: { params: Promise<{ tab?: string[] }> }) {
  const { tab = [] } = await params;
  redirect(`/mobile/pasajero?tab=${TAB[tab[0] ?? "viajes"] ?? "viaje"}`);
}
