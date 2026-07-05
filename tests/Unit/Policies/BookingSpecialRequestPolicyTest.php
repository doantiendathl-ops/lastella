<?php

namespace Tests\Unit\Policies;

use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\User;
use App\Policies\BookingSpecialRequestPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSpecialRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    private BookingSpecialRequestPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->policy = new BookingSpecialRequestPolicy();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    // -------------------------------------------------------------------------
    // ADMIN matrix
    // -------------------------------------------------------------------------

    public function test_admin_can_view(): void
    {
        $admin   = $this->userWithRole('ADMIN');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->view($admin, $request));
    }

    public function test_admin_can_create(): void
    {
        $admin   = $this->userWithRole('ADMIN');
        $booking = Booking::factory()->create();

        $this->assertTrue($this->policy->create($admin, $booking));
    }

    public function test_admin_can_fulfill(): void
    {
        $admin   = $this->userWithRole('ADMIN');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->fulfill($admin, $request));
    }

    public function test_admin_can_cancel(): void
    {
        $admin   = $this->userWithRole('ADMIN');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->cancel($admin, $request));
    }

    // -------------------------------------------------------------------------
    // MANAGER matrix
    // -------------------------------------------------------------------------

    public function test_manager_can_view(): void
    {
        $manager = $this->userWithRole('MANAGER');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->view($manager, $request));
    }

    public function test_manager_can_create(): void
    {
        $manager = $this->userWithRole('MANAGER');
        $booking = Booking::factory()->create();

        $this->assertTrue($this->policy->create($manager, $booking));
    }

    public function test_manager_can_fulfill(): void
    {
        $manager = $this->userWithRole('MANAGER');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->fulfill($manager, $request));
    }

    public function test_manager_can_cancel(): void
    {
        $manager = $this->userWithRole('MANAGER');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->cancel($manager, $request));
    }

    // -------------------------------------------------------------------------
    // RECEPTION matrix
    // -------------------------------------------------------------------------

    public function test_reception_can_view(): void
    {
        $reception = $this->userWithRole('RECEPTION');
        $request   = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->view($reception, $request));
    }

    public function test_reception_can_create(): void
    {
        $reception = $this->userWithRole('RECEPTION');
        $booking   = Booking::factory()->create();

        $this->assertTrue($this->policy->create($reception, $booking));
    }

    public function test_reception_cannot_fulfill(): void
    {
        $reception = $this->userWithRole('RECEPTION');
        $request   = BookingSpecialRequest::factory()->create();

        $this->assertFalse($this->policy->fulfill($reception, $request));
    }

    public function test_reception_cannot_cancel(): void
    {
        $reception = $this->userWithRole('RECEPTION');
        $request   = BookingSpecialRequest::factory()->create();

        $this->assertFalse($this->policy->cancel($reception, $request));
    }

    // -------------------------------------------------------------------------
    // HOUSEKEEPING matrix
    // -------------------------------------------------------------------------

    public function test_housekeeping_can_view(): void
    {
        $hk      = $this->userWithRole('HOUSEKEEPING');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->view($hk, $request));
    }

    public function test_housekeeping_cannot_create(): void
    {
        $hk      = $this->userWithRole('HOUSEKEEPING');
        $booking = Booking::factory()->create();

        $this->assertFalse($this->policy->create($hk, $booking));
    }

    public function test_housekeeping_can_fulfill(): void
    {
        $hk      = $this->userWithRole('HOUSEKEEPING');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertTrue($this->policy->fulfill($hk, $request));
    }

    public function test_housekeeping_cannot_cancel(): void
    {
        $hk      = $this->userWithRole('HOUSEKEEPING');
        $request = BookingSpecialRequest::factory()->create();

        $this->assertFalse($this->policy->cancel($hk, $request));
    }

    // -------------------------------------------------------------------------
    // ACCOUNTANT matrix
    // -------------------------------------------------------------------------

    public function test_accountant_cannot_view(): void
    {
        $accountant = $this->userWithRole('ACCOUNTANT');
        $request    = BookingSpecialRequest::factory()->create();

        $this->assertFalse($this->policy->view($accountant, $request));
    }

    public function test_accountant_cannot_create(): void
    {
        $accountant = $this->userWithRole('ACCOUNTANT');
        $booking    = Booking::factory()->create();

        $this->assertFalse($this->policy->create($accountant, $booking));
    }

    public function test_accountant_cannot_fulfill(): void
    {
        $accountant = $this->userWithRole('ACCOUNTANT');
        $request    = BookingSpecialRequest::factory()->create();

        $this->assertFalse($this->policy->fulfill($accountant, $request));
    }

    public function test_accountant_cannot_cancel(): void
    {
        $accountant = $this->userWithRole('ACCOUNTANT');
        $request    = BookingSpecialRequest::factory()->create();

        $this->assertFalse($this->policy->cancel($accountant, $request));
    }
}
