import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Turismo SaaS",
  description: "CRM y ERP para agencias de turismo",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es">
      <body className="antialiased text-slate-900">{children}</body>
    </html>
  );
}
