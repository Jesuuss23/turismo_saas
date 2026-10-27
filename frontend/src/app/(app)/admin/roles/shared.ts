export type RoleRow = { id: number; name: string; display_name: string; description: string | null; scope: string; scope_label: string; is_custom: boolean; experience: string; permissions: number; users: number };

export const ROLE_TABS = [{ key: "", label: "Roles" }, { key: "mapa", label: "Mapa de acceso" }];
