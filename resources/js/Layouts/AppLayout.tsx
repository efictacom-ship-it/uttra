import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';
import Icon from '@/components/Icon';
import { Alert } from '@/components/ui';
import type { PageProps } from '@/types';

const nav: { group: string; items: { href: string; label: string; icon: string }[] }[] = [
    {
        group: 'التشغيل اليومي',
        items: [
            { href: '/dashboard', label: 'الرئيسية', icon: 'dashboard' },
            { href: '/pos', label: 'الكاشير', icon: 'pos' },
            { href: '/sales', label: 'فواتير البيع', icon: 'receipt' },
            { href: '/purchases', label: 'المشتريات', icon: 'truck' },
        ],
    },
    {
        group: 'المخزون والأصناف',
        items: [
            { href: '/items', label: 'الأصناف والخدمات', icon: 'box' },
            { href: '/inventory/balances', label: 'أرصدة المخزون', icon: 'clipboard' },
            { href: '/stock-counts', label: 'الجرد', icon: 'scan' },
            { href: '/inventory/adjustments', label: 'التلف والتسويات', icon: 'alert' },
        ],
    },
    {
        group: 'المال والمحاسبة',
        items: [
            { href: '/parties', label: 'العملاء والموردون', icon: 'users' },
            { href: '/payments', label: 'المقبوضات والمدفوعات', icon: 'wallet' },
            { href: '/expenses', label: 'المصروفات', icon: 'file' },
            { href: '/cash-sessions', label: 'الصندوق', icon: 'wallet' },
            { href: '/accounting/journals', label: 'القيود والأستاذ', icon: 'book' },
            { href: '/accounting/periods', label: 'إقفال الفترات', icon: 'clipboard' },
        ],
    },
    {
        group: 'التقارير والإعداد',
        items: [
            { href: '/reports', label: 'التقارير', icon: 'chart' },
            { href: '/einvoices', label: 'الفوترة الإلكترونية', icon: 'file' },
            { href: '/settings', label: 'الإعدادات', icon: 'settings' },
        ],
    },
];

export default function AppLayout({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }) {
    const { auth, business, flash, url } = usePage<PageProps>().props as PageProps & { url?: string };
    const current = typeof window !== 'undefined' ? window.location.pathname : (url ?? '');

    return (
        <div className="min-h-dvh lg:flex">
            <Head title={title} />

            <aside className="no-print border-slate-200 bg-ink-900 text-slate-300 lg:min-h-dvh lg:w-64 lg:shrink-0">
                <div className="flex items-center gap-2 px-5 py-4">
                    <span className="grid size-9 place-items-center rounded-lg bg-brand-500 text-white">
                        <Icon name="book" className="size-5" />
                    </span>
                    <div className="leading-tight">
                        <p className="text-sm font-bold text-white">{business?.trade_name || business?.legal_name || 'مكتبة القرطاسية'}</p>
                        <p className="text-[11px] text-slate-400">محاسبة ومخزون ونقاط بيع</p>
                    </div>
                </div>

                <nav className="max-h-[50vh] overflow-y-auto px-3 pb-4 lg:max-h-none">
                    {nav.map((section) => (
                        <div key={section.group} className="mb-4">
                            <p className="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{section.group}</p>
                            <ul className="space-y-0.5">
                                {section.items.map((item) => {
                                    const active = current === item.href || current.startsWith(item.href + '/');
                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                className={`flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition ${
                                                    active ? 'bg-brand-600 font-semibold text-white' : 'hover:bg-white/5 hover:text-white'
                                                }`}
                                            >
                                                <Icon name={item.icon} className="size-4" />
                                                {item.label}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ))}
                </nav>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="no-print flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-3">
                    <div>
                        <h1 className="text-lg font-bold text-ink-900">{title}</h1>
                        {business && !business.vat_registered && (
                            <p className="text-xs text-amber-700">المنشأة غير مسجّلة في ضريبة القيمة المضافة — الفواتير تُصدر بدون ضريبة.</p>
                        )}
                    </div>
                    <div className="flex items-center gap-3">
                        {actions}
                        <div className="text-end text-xs leading-tight">
                            <p className="font-semibold text-ink-900">{auth.user?.name}</p>
                            <p className="text-slate-500">
                                {auth.user?.role === 'owner' ? 'المالك' : auth.user?.role === 'accountant' ? 'محاسب' : 'كاشير'}
                            </p>
                        </div>
                        <button
                            onClick={() => router.post('/logout')}
                            className="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-rose-700"
                            title="خروج"
                        >
                            <Icon name="logout" className="size-4" />
                        </button>
                    </div>
                </header>

                <main className="flex-1 space-y-4 p-5">
                    {flash?.success && <Alert tone="success">{flash.success}</Alert>}
                    {flash?.error && <Alert tone="error">{flash.error}</Alert>}
                    {children}
                </main>
            </div>
        </div>
    );
}
