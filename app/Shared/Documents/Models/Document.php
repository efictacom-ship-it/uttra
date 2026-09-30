<?php

declare(strict_types=1);

namespace App\Shared\Documents\Models;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\EInvoicing\Models\EinvoiceDocument;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * رأس المستند الموحد لكل الأنواع: sale, sale_return, purchase, purchase_return,
 * expense, stock_opening, stock_adjustment, material_usage.
 * الجدول مشترك بين موديولات Sales وPurchasing وInventory وFinance، لذلك يقع في Shared.
 */
class Document extends Model
{
    public const KIND_SALE = 'sale';
    public const KIND_SALE_RETURN = 'sale_return';
    public const KIND_PURCHASE = 'purchase';
    public const KIND_PURCHASE_RETURN = 'purchase_return';
    public const KIND_EXPENSE = 'expense';
    public const KIND_STOCK_OPENING = 'stock_opening';
    public const KIND_STOCK_ADJUSTMENT = 'stock_adjustment';
    public const KIND_MATERIAL_USAGE = 'material_usage';

    protected $table = 'documents';

    protected $guarded = ['id'];

    protected $casts = [
        'business_date' => 'date',
        'due_date' => 'date',
        'issued_at' => 'datetime',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'seller_snapshot' => 'array',
        'buyer_snapshot' => 'array',
        'subtotal' => 'string',
        'discount_total' => 'string',
        'net_total' => 'string',
        'tax_total' => 'string',
        'rounding_amount' => 'string',
        'grand_total' => 'string',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class, 'document_id')->orderBy('line_number');
    }

    public function taxTotals(): HasMany
    {
        return $this->hasMany(DocumentTaxTotal::class, 'document_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_document_id');
    }

    public function journalEntry(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'document_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'document_id');
    }

    public function einvoiceDocument(): HasOne
    {
        return $this->hasOne(EinvoiceDocument::class, 'document_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** المستندات التي تحمل قيمة تجارية وضريبة (مقابل مستندات المخزون غير المالية). */
    public function isCommercial(): bool
    {
        return in_array($this->kind, [
            self::KIND_SALE, self::KIND_SALE_RETURN, self::KIND_PURCHASE,
            self::KIND_PURCHASE_RETURN, self::KIND_EXPENSE,
        ], true);
    }
}
