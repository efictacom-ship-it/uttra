import { lazy, Suspense, useState } from 'react';
import { Button } from './ui';

const BarcodeScannerModal = lazy(() => import('./BarcodeScannerModal'));

/** زرار يفتح الكاميرا ويمسح باركود/QR، ثم يعيد القيمة عبر onScan ويغلق تلقائيًا. */
export default function BarcodeScannerButton({ onScan, label = 'مسح بالكاميرا' }: { onScan: (code: string) => void; label?: string }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button type="button" variant="secondary" icon="scan" title="مسح بالكاميرا" onClick={() => setOpen(true)}>
                {label}
            </Button>
            {open && (
                <Suspense fallback={null}>
                    <BarcodeScannerModal
                        onDetected={(code) => {
                            setOpen(false);
                            onScan(code);
                        }}
                        onClose={() => setOpen(false)}
                    />
                </Suspense>
            )}
        </>
    );
}
