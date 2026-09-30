<?php

namespace App\Providers;

use App\Modules\EInvoicing\Contracts\EInvoicingProvider;
use App\Modules\EInvoicing\Providers\MockEInvoicingProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // مزوّد الفوترة الإلكترونية الوحيد المطبَّق اليوم هو المحاكاة (mock)؛
        // استبداله بمزود حقيقي يتم من هنا فقط، دون لمس بقية طبقة الفوترة.
        $this->app->bind(EInvoicingProvider::class, MockEInvoicingProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
