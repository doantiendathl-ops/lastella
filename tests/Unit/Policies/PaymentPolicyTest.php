<?php

namespace Tests\Unit\Policies;

use App\Models\BookingPayment;
use App\Models\User;
use App\Policies\BookingPaymentPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_any_payment(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        $oldPayment = BookingPayment::factory()->create([
            'created_at' => now()->subDays(10),
        ]);

        $policy = new BookingPaymentPolicy();

        $this->assertTrue($policy->delete($admin, $oldPayment));
    }

    public function test_manager_can_delete_todays_payment(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $todayPayment = BookingPayment::factory()->create([
            'created_at' => now(),
        ]);

        $policy = new BookingPaymentPolicy();

        $this->assertTrue($policy->delete($manager, $todayPayment));
    }

    public function test_manager_cannot_delete_old_payment(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $oldPayment = BookingPayment::factory()->create([
            'created_at' => now()->subDay(),
        ]);

        $policy = new BookingPaymentPolicy();

        $this->assertFalse($policy->delete($manager, $oldPayment));
    }

    public function test_reception_cannot_delete_payment(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $payment = BookingPayment::factory()->create([
            'created_at' => now(),
        ]);

        $policy = new BookingPaymentPolicy();

        $this->assertFalse($policy->delete($reception, $payment));
    }

    public function test_accountant_cannot_delete_payment(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $payment = BookingPayment::factory()->create([
            'created_at' => now(),
        ]);

        $policy = new BookingPaymentPolicy();

        $this->assertFalse($policy->delete($accountant, $payment));
    }
}
