export function Field(props: React.InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  const { label, ...input } = props;
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <input {...input} className="w-full rounded-md border border-slate-300 px-3 py-2 outline-none focus:border-sky-600 focus:ring-2 focus:ring-sky-100" />
    </label>
  );
}

export function Button({ className = "", ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...props}
      className={`rounded-md bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50 ${className}`}
    />
  );
}

export function Alert({ kind = "error", children }: { kind?: "error" | "info"; children: React.ReactNode }) {
  const tone = kind === "error" ? "bg-red-50 text-red-800 ring-red-200" : "bg-emerald-50 text-emerald-800 ring-emerald-200";
  return <p className={`rounded-md px-3 py-2 text-sm ring-1 ${tone}`}>{children}</p>;
}
