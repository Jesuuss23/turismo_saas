import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Spec 18: la reseña del pasajero sube hasta 3 fotos de 2 MB por una server action.
  experimental: { serverActions: { bodySizeLimit: "8mb" } },
};

export default nextConfig;
