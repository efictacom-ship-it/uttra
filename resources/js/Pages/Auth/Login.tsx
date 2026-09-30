import { Head, useForm } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { Alert, Button, Field, TextInput } from '@/components/ui';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false as boolean });

    return (
        <div className="grid min-h-dvh lg:grid-cols-2">
            <Head title="تسجيل الدخول" />

            <div className="flex items-center justify-center p-6">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/login');
                    }}
                    className="w-full max-w-sm space-y-5"
                >
                    <div className="space-y-1">
                        <span className="grid size-11 place-items-center rounded-xl bg-brand-600 text-white">
                            <Icon name="book" />
                        </span>
                        <h1 className="pt-3 text-2xl font-bold text-ink-900">تسجيل الدخول</h1>
                        <p className="text-sm text-slate-500">نظام محاسبة ومخزون ونقاط بيع للمكتبة والخدمات الطلابية.</p>
                    </div>

                    {form.errors.email && <Alert>{form.errors.email}</Alert>}

                    <Field label="البريد الإلكتروني" required>
                        <TextInput
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            autoComplete="username"
                            autoFocus
                            dir="ltr"
                        />
                    </Field>

                    <Field label="كلمة المرور" required error={form.errors.password}>
                        <TextInput
                            type="password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            autoComplete="current-password"
                            dir="ltr"
                        />
                    </Field>

                    <label className="flex items-center gap-2 text-sm text-ink-700">
                        <input
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(e) => form.setData('remember', e.target.checked)}
                            className="size-4 rounded border-slate-300 text-brand-600"
                        />
                        تذكّرني على هذا الجهاز
                    </label>

                    <Button type="submit" size="lg" loading={form.processing} className="w-full">
                        دخول
                    </Button>
                </form>
            </div>

            <div className="hidden bg-ink-900 lg:block">
                <div className="flex h-full flex-col justify-center gap-6 p-12 text-slate-300">
                    <h2 className="text-3xl font-bold leading-snug text-white">
                        كاشير سريع، مخزون مضبوط،
                        <br />
                        ومحاسبة مزدوجة القيد.
                    </h2>
                    <ul className="space-y-3 text-sm">
                        {[
                            'بيع القرطاسية والخدمات الطلابية في فاتورة واحدة',
                            'متوسط تكلفة متحرك ومنع المخزون السالب',
                            'قيود تلقائية متوازنة لكل مستند ودفعة',
                            'تقارير مبيعات ومخزون وضريبة متطابقة مع الدفاتر',
                        ].map((line) => (
                            <li key={line} className="flex items-start gap-2">
                                <Icon name="check" className="mt-0.5 size-4 text-brand-400" />
                                {line}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}
