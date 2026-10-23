import { LoginForm } from "./LoginForm";

export const metadata = { title: "Ingresar · Turismo SaaS" };

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ next?: string; tenant?: string }> }) {
  const { next, tenant } = await searchParams;

  return (
    <>
      <h1 className="mt-2 text-xl font-semibold text-slate-900">Ingresar</h1>
      <p className="mt-1 text-sm text-slate-500">Use las credenciales de su agencia.</p>
      <LoginForm next={next} tenant={tenant} />
    </>
  );
}
