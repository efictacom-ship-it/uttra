import { Link } from '@inertiajs/react';

export default function Pagination({ links }: { links: { url: string | null; label: string; active: boolean }[] }) {
    if (!links || links.length <= 3) return null;

    return (
        <nav className="mt-4 flex flex-wrap items-center gap-1" aria-label="ترقيم الصفحات">
            {links.map((link, i) => {
                const label = link.label.replace('&laquo; Previous', 'السابق').replace('Next &raquo;', 'التالي');

                return link.url ? (
                    <Link
                        key={i}
                        href={link.url}
                        preserveScroll
                        className={`rounded-lg px-3 py-1.5 text-sm transition ${
                            link.active ? 'bg-brand-600 font-semibold text-white' : 'border border-slate-200 bg-white hover:bg-slate-50'
                        }`}
                    >
                        {label}
                    </Link>
                ) : (
                    <span key={i} className="rounded-lg px-3 py-1.5 text-sm text-slate-400">
                        {label}
                    </span>
                );
            })}
        </nav>
    );
}
