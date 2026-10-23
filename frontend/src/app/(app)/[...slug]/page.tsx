/** Pantallas de módulos aún no construidos. El acceso ya lo validó el middleware con el backend. */
export default async function ModulePlaceholder({ params }: { params: Promise<{ slug: string[] }> }) {
  const { slug } = await params;

  return (
    <section className="space-y-2">
      <p className="font-mono text-sm text-slate-500">/{slug.join("/")}</p>
      <h1 className="text-xl font-semibold text-slate-900">Módulo en construcción</h1>
      <p className="text-sm text-slate-600">Esta pantalla se implementará con su especificación de módulo.</p>
    </section>
  );
}
