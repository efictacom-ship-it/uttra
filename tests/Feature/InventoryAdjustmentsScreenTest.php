<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class InventoryAdjustmentsScreenTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_adjustments_screen_renders(): void
    {
        $this->actingAs($this->owner)
            ->get('/inventory/adjustments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Adjustments'));
    }
}
