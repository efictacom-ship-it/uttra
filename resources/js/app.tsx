import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const appName = 'نظام مكتبة القرطاسية';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) {
            throw new Error(`الصفحة ${name} غير موجودة`);
        }
        return page as never;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#0f766e' },
});
