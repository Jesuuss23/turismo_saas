import { redirect } from "next/navigation";
import { isPlatformSession } from "@/lib/session";

export default async function AdminIndex() {
  redirect((await isPlatformSession()) ? "/admin/tenants" : "/admin/usuarios");
}
