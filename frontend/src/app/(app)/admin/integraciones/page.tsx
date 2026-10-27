import { redirect } from "next/navigation";

export default function IntegrationsIndex() {
  redirect("/admin/integraciones/adapters");
}
