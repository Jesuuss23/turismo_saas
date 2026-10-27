import { redirect } from "next/navigation";

/** `#/canales/b2b` abre la administración del canal (spec 08 §2). */
export default function B2bIndex() {
  redirect("/canales/b2b/admin");
}
