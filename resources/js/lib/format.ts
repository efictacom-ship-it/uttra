const nf = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qf = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 3 });

/** مبلغ للعرض فقط. الحساب المعتمد يقع على السيرفر. */
export function money(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '0.00';
    return nf.format(Number(value));
}

export function qty(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '0';
    return qf.format(Number(value));
}

export function dateAr(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    return new Intl.DateTimeFormat('ar-SA-u-nu-latn', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: 'Asia/Riyadh',
    }).format(d);
}

export function dateTimeAr(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    return new Intl.DateTimeFormat('ar-SA-u-nu-latn', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Riyadh',
    }).format(d);
}

export const docKindLabels: Record<string, string> = {
    sale: 'فاتورة بيع',
    sale_return: 'مرتجع بيع',
    purchase: 'فاتورة شراء',
    purchase_return: 'مرتجع شراء',
    expense: 'مصروف',
    stock_opening: 'رصيد افتتاحي',
    stock_adjustment: 'تسوية مخزون',
    material_usage: 'استهلاك خامات',
};

export const statusLabels: Record<string, string> = {
    draft: 'مسودة',
    prepared: 'مجهّز',
    posted: 'مرحّل',
    cancelled: 'ملغي',
};

export const einvoiceStatusLabels: Record<string, string> = {
    ready: 'جاهز',
    pending: 'قيد الإرسال',
    reported: 'مُبلَّغ',
    cleared: 'مُخلَّص',
    rejected: 'مرفوض',
    connection_error: 'تعذر الاتصال',
};

export const methodLabels: Record<string, string> = {
    cash: 'نقدي',
    card: 'شبكة',
    bank_transfer: 'تحويل بنكي',
    other: 'أخرى',
};
