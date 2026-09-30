import { type ReactNode } from 'react';
import Icon from './Icon';

export function Card({ title, action, children, className = '' }: { title?: string; action?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={`rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}>
            {(title || action) && (
                <header className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
                    {title && <h2 className="text-base font-semibold text-ink-900">{title}</h2>}
                    {action}
                </header>
            )}
            <div className="p-5">{children}</div>
        </section>
    );
}

export function Field({
    label,
    error,
    hint,
    required,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    children: ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-ink-700">
                {label}
                {required && <span className="ms-1 text-rose-600">*</span>}
            </span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
            {error && <span className="mt-1 block text-xs font-medium text-rose-600">{error}</span>}
        </label>
    );
}

const inputBase =
    'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-ink-900 transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-100 disabled:bg-slate-50 disabled:text-slate-500';

export function TextInput(props: React.InputHTMLAttributes<HTMLInputElement>) {
    const { className = '', ...rest } = props;
    return <input {...rest} className={`${inputBase} ${className}`} />;
}

export function NumberInput(props: React.InputHTMLAttributes<HTMLInputElement>) {
    const { className = '', ...rest } = props;
    return <input {...rest} type="text" inputMode="decimal" className={`${inputBase} num ${className}`} />;
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
    const { className = '', ...rest } = props;
    return <select {...rest} className={`${inputBase} ${className}`} />;
}

export function TextArea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
    const { className = '', ...rest } = props;
    return <textarea {...rest} className={`${inputBase} ${className}`} />;
}

export function Button({
    variant = 'primary',
    size = 'md',
    loading,
    icon,
    children,
    className = '',
    ...rest
}: {
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    size?: 'sm' | 'md' | 'lg';
    loading?: boolean;
    icon?: string;
} & React.ButtonHTMLAttributes<HTMLButtonElement>) {
    const variants = {
        primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-200',
        secondary: 'border border-slate-300 bg-white text-ink-900 hover:bg-slate-50 disabled:text-slate-400',
        danger: 'bg-rose-600 text-white hover:bg-rose-700 disabled:bg-rose-200',
        ghost: 'text-ink-700 hover:bg-slate-100',
    };
    const sizes = { sm: 'px-2.5 py-1.5 text-xs', md: 'px-4 py-2 text-sm', lg: 'px-5 py-2.5 text-base' };

    return (
        <button
            {...rest}
            disabled={rest.disabled || loading}
            className={`inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition disabled:cursor-not-allowed ${variants[variant]} ${sizes[size]} ${className}`}
        >
            {loading && (
                <svg className="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
                    <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                </svg>
            )}
            {icon && !loading && <Icon name={icon} className="size-4" />}
            {children}
        </button>
    );
}

export function Badge({ tone = 'slate', children }: { tone?: 'slate' | 'green' | 'amber' | 'rose' | 'blue'; children: ReactNode }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700',
        green: 'bg-emerald-100 text-emerald-800',
        amber: 'bg-amber-100 text-amber-800',
        rose: 'bg-rose-100 text-rose-800',
        blue: 'bg-sky-100 text-sky-800',
    };
    return <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${tones[tone]}`}>{children}</span>;
}

export function Alert({ tone = 'error', children }: { tone?: 'error' | 'warning' | 'info' | 'success'; children: ReactNode }) {
    const tones = {
        error: 'border-rose-200 bg-rose-50 text-rose-800',
        warning: 'border-amber-200 bg-amber-50 text-amber-900',
        info: 'border-sky-200 bg-sky-50 text-sky-900',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    };
    return (
        <div className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm ${tones[tone]}`} role="alert">
            <Icon name={tone === 'success' ? 'check' : 'alert'} className="mt-0.5 size-4 shrink-0" />
            <div>{children}</div>
        </div>
    );
}

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-2 py-12 text-center">
            <div className="rounded-full bg-slate-100 p-3 text-slate-400">
                <Icon name="file" className="size-6" />
            </div>
            <p className="font-semibold text-ink-900">{title}</p>
            {description && <p className="max-w-md text-sm text-slate-500">{description}</p>}
            {action}
        </div>
    );
}

export function Table({ head, children, empty }: { head: string[]; children: ReactNode; empty?: boolean }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-slate-200 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {head.map((h) => (
                            <th key={h} className="whitespace-nowrap px-3 py-2.5 text-start font-semibold">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {empty ? (
                        <tr>
                            <td colSpan={head.length} className="px-3 py-10 text-center text-slate-500">
                                لا توجد بيانات لهذه الفترة أو هذا البحث.
                            </td>
                        </tr>
                    ) : (
                        children
                    )}
                </tbody>
            </table>
        </div>
    );
}

export function Money({ value, className = '' }: { value: string | number | null | undefined; className?: string }) {
    const n = Number(value ?? 0);
    return <span className={`num ${n < 0 ? 'text-rose-700' : ''} ${className}`}>{n.toFixed(2)}</span>;
}
