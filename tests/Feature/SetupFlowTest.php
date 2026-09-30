<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Models\BusinessSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_setup_flow_opens_the_system(): void
    {
        $this->post('/setup/first-user', [
            'name' => 'مالك المكتبة',
            'email' => 'owner@example.test',
            'password' => 'kalima-sirriya-2026',
            'password_confirmation' => 'kalima-sirriya-2026',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticated();

        // لا يمكن إنشاء مستخدم أول مرة ثانية
        $this->post('/setup/first-user', [
            'name' => 'دخيل', 'email' => 'x@example.test',
            'password' => 'kalima-sirriya-2026', 'password_confirmation' => 'kalima-sirriya-2026',
        ])->assertSessionHasErrors('email');

        $this->post('/setup/business', $this->businessPayload())->assertSessionHasNoErrors();
        $this->post('/setup/chart')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', ['code' => '1120']);
        $this->assertDatabaseHas('units', ['code' => 'PCE']);
        $this->assertDatabaseHas('warehouses', ['code' => 'MAIN']);
        $this->assertDatabaseCount('financial_accounts', 3);
        $this->assertSame([], app(\App\Modules\Accounting\Services\AccountResolver::class)->missingRequired());

        $this->post('/setup/periods', [
            'name' => 'السنة المالية 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        // فترة متداخلة مرفوضة
        $this->post('/setup/periods', [
            'name' => 'تداخل', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31',
        ])->assertSessionHasErrors();

        $this->post('/setup/complete')->assertRedirect('/dashboard');

        $this->assertNotNull(BusinessSetting::query()->find(1)->setup_completed_at);
        $this->get('/dashboard')->assertOk();
    }

    public function test_dashboard_is_blocked_until_setup_is_completed(): void
    {
        $user = $this->makeOwner();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/setup');
    }

    public function test_setup_cannot_complete_while_required_accounts_are_unmapped(): void
    {
        $user = $this->makeOwner();

        $this->actingAs($user)->post('/setup/business', $this->businessPayload())->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/setup/chart')->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/setup/periods', [
            'name' => 'فترة', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        ]);

        AccountMapping::query()->where('slug', 'inventory')->update(['account_id' => null]);

        $this->actingAs($user)->post('/setup/complete')->assertSessionHasErrors('message');
        $this->assertNull(BusinessSetting::query()->find(1)->setup_completed_at);
    }

    public function test_vat_number_is_validated_when_registered(): void
    {
        $user = $this->makeOwner();

        $this->actingAs($user)
            ->post('/setup/business', $this->businessPayload(['vat_registered' => true, 'vat_number' => '12345']))
            ->assertSessionHasErrors('vat_number');
    }

    private function businessPayload(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'مؤسسة المكتبة للقرطاسية',
            'trade_name' => 'مكتبة الطالب',
            'registration_number' => '1010101010',
            'vat_registered' => false,
            'vat_number' => null,
            'einvoice_phase' => 'none',
            'phone' => '0551234567',
            'email' => 'shop@example.test',
            'rounding_decimals' => 2,
            'rounding_mode' => 'half_up',
            'fiscal_year_start_month' => 1,
            'receipt_note_ar' => 'شكرًا لزيارتكم',
            'invoice_note_ar' => null,
            'address' => ['street' => 'طريق الملك', 'building_number' => '1234', 'district' => 'النخيل', 'city' => 'الرياض', 'postal_code' => '12345', 'additional_number' => '6789'],
        ], $overrides);
    }

    private function makeOwner(): User
    {
        $user = new User(['name' => 'المالك', 'email' => 'owner2@example.test', 'role' => 'owner', 'is_active' => true]);
        $user->setPassword('kalima-sirriya-2026');
        $user->save();

        return $user;
    }
}
