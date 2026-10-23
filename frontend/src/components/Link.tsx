import NextLink from "next/link";
import type { ComponentProps } from "react";

/**
 * Link sin prefetch por defecto: cada prefetch pasa por el middleware, que consulta
 * /me/access/check en el backend; con decenas de enlaces visibles se agotaba el rate limit
 * por usuario (SEC-BR-25). Pase `prefetch` explícito si un enlace lo necesita.
 */
export default function Link({ prefetch = false, ...props }: ComponentProps<typeof NextLink>) {
  return <NextLink prefetch={prefetch} {...props} />;
}
