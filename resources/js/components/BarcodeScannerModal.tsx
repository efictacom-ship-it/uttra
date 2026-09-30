import { useEffect, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import Icon from './Icon';
import { Alert, Button } from './ui';

export default function BarcodeScannerModal({ onDetected, onClose }: { onDetected: (code: string) => void; onClose: () => void }) {
    const elementId = useRef(`barcode-scanner-${Math.random().toString(36).slice(2)}`).current;
    const scannerRef = useRef<Html5Qrcode | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        let isRunning = false;
        const scanner = new Html5Qrcode(elementId, { verbose: false });
        scannerRef.current = scanner;

        scanner
            .start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 260, height: 160 } },
                (decodedText) => {
                    if (!cancelled) onDetected(decodedText);
                },
                () => {
                    // إطار بلا باركود مقروء، متوقع أثناء البحث عن الصنف
                },
            )
            .then(() => {
                isRunning = !cancelled;
            })
            .catch(() => {
                if (!cancelled) setError('تعذّر فتح الكاميرا. تأكد من السماح للمتصفح بالوصول إليها.');
            });

        return () => {
            cancelled = true;
            if (!isRunning) return;
            try {
                scannerRef.current
                    ?.stop()
                    .then(() => scannerRef.current?.clear())
                    .catch(() => {});
            } catch {
                // الماسح كان متوقفًا أصلًا
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
            <div className="w-full max-w-sm rounded-xl bg-white p-4 shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="mb-3 flex items-center justify-between">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-ink-900">
                        <Icon name="scan" className="size-4" />
                        وجّه الكاميرا نحو الباركود
                    </h3>
                    <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                        إغلاق
                    </Button>
                </div>
                {error ? (
                    <Alert>{error}</Alert>
                ) : (
                    <div id={elementId} className="overflow-hidden rounded-lg bg-black" />
                )}
            </div>
        </div>
    );
}
