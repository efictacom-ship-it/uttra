<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Shared\Money\RoundingPolicy;
use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    protected $table = 'business_settings';

    public $incrementing = false;

    const CREATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'vat_registered' => 'boolean',
        'address_json' => 'array',
        'rounding_decimals' => 'integer',
        'fiscal_year_start_month' => 'integer',
        'setup_completed_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::singleton();
    }

    /** صف واحد فقط برقم 1 (تضمنه CHECK في القاعدة)، لذلك يُجبر المفتاح هنا. */
    public static function singleton(): self
    {
        $settings = static::query()->find(1);

        if (! $settings) {
            $settings = new self;
            $settings->forceFill(['id' => 1]);
        }

        return $settings;
    }

    public static function persist(array $attributes): self
    {
        $settings = static::singleton();
        $settings->fill($attributes);
        $settings->forceFill(['id' => 1]);
        $settings->save();

        return $settings;
    }

    public function roundingPolicy(): RoundingPolicy
    {
        return new RoundingPolicy((int) ($this->rounding_decimals ?? 2), (string) ($this->rounding_mode ?? 'half_up'));
    }

    public function isSetupComplete(): bool
    {
        return $this->setup_completed_at !== null;
    }
}
