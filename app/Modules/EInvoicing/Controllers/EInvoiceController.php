<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Controllers;

use App\Modules\EInvoicing\Models\EinvoiceDevice;
use App\Modules\EInvoicing\Models\EinvoiceDocument;
use App\Modules\EInvoicing\Services\EInvoiceGenerationService;
use App\Modules\EInvoicing\Services\EInvoiceOutboxProcessor;
use App\Modules\EInvoicing\Services\EInvoiceSubmissionService;
use App\Shared\Documents\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشة الفوترة الإلكترونية (طبقة محاكاة/mock بالكامل — راجع MockEInvoicingProvider).
 * التوليد يدوي هنا (زر لكل فاتورة) بدل ربطه تلقائيًا داخل ترحيل البيع، حتى يبقى
 * منطق الترحيل المحاسبي (SalesPostingService) مستقلًا تمامًا ومُختبرًا بمعزل عنها.
 */
class EInvoiceController
{
    public function __construct(
        private readonly EInvoiceGenerationService $generation,
        private readonly EInvoiceSubmissionService $submissions,
        private readonly EInvoiceOutboxProcessor $outbox,
    ) {}

    public function index(Request $request): Response
    {
        $documents = EinvoiceDocument::query()
            ->with('document:id,document_number,kind,grand_total')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('EInvoicing/Index', [
            'documents' => $documents,
            'filters' => $request->only('status'),
            'devices' => EinvoiceDevice::query()->where('is_active', true)->get(['id', 'serial_number', 'environment']),
        ]);
    }

    /** توليد سجل فوترة إلكترونية جديد لمستند بيع/مرتجع بيع مرحّل. */
    public function generate(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:einvoice_devices,id'],
        ]);

        $einvoiceDocument = $this->generation->generate($document->id, (int) $data['device_id']);

        return back()->with('success', 'تم توليد سجل الفوترة الإلكترونية رقم '.$einvoiceDocument->id.' (محاكاة).');
    }

    public function submit(EinvoiceDocument $einvoiceDocument): RedirectResponse
    {
        $updated = $this->submissions->submit($einvoiceDocument->id);

        return back()->with('success', 'حالة الإرسال الآن: '.$updated->status.' (محاكاة).');
    }

    public function retry(EinvoiceDocument $einvoiceDocument): RedirectResponse
    {
        $updated = $this->submissions->retry($einvoiceDocument->id);

        return back()->with('success', 'أُعيدت المحاولة؛ الحالة الآن: '.$updated->status.' (محاكاة).');
    }

    /** معالجة يدوية لصندوق الصادر (بديل عامل الطابور الحقيقي في هذه النسخة). */
    public function processOutbox(): RedirectResponse
    {
        $result = $this->outbox->processPending();

        return back()->with('success', "تمت معالجة {$result['processed']} مهمة، وفشلت {$result['failed']}.");
    }
}
