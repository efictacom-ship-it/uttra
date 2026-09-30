export type Role = 'owner' | 'accountant' | 'cashier';

export interface AuthUser {
    id: number;
    name: string;
    role: Role;
}

export interface BusinessShared {
    legal_name: string;
    trade_name: string | null;
    vat_registered: boolean;
    vat_number: string | null;
    currency: string;
    einvoice_phase: 'none' | 'phase1' | 'phase2';
    setup_completed: boolean;
}

export interface PageProps {
    auth: { user: AuthUser | null };
    business: BusinessShared | null;
    flash: { success?: string; error?: string };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface Option {
    id: number;
    label: string;
}
