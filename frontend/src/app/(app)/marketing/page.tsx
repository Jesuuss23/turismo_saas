import { redirect } from "next/navigation";

/** El grupo «Marketing» abre en Campañas (spec 14 §2). */
export default function MarketingHome() {
  redirect("/marketing/campanas");
}
