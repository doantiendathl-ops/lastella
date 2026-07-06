<?php

namespace Database\Factories;

use App\Enums\CleaningReason;
use App\Enums\InspectionResult;
use App\Enums\RoomStatus;
use App\Models\CleaningRecord;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CleaningRecord>
 */
class CleaningRecordFactory extends Factory
{
    protected $model = CleaningRecord::class;

    public function definition(): array
    {
        $startedAt = now()->subMinutes(30);

        return [
            'room_id'            => Room::factory(),
            'assignment_id'      => null,
            'cleaned_by'         => User::factory(),
            'room_status_before' => RoomStatus::VacantDirty->value,
            'room_status_after'  => null,
            'reason'             => CleaningReason::Checkout,
            'started_at'         => $startedAt,
            'completed_at'       => null,
            'duration_minutes'   => null,
            'cleaning_notes'     => null,
            'inspection_result'  => null,
            'inspected_by'       => null,
            'inspected_at'       => null,
            'inspection_notes'   => null,
        ];
    }

    public function awaitingInspection(): static
    {
        return $this->state(fn () => [
            'completed_at'      => now(),
            'duration_minutes'  => 25,
            'room_status_after' => null,
            'inspection_result' => null,
        ]);
    }

    public function passed(): static
    {
        return $this->state(fn () => [
            'completed_at'      => now()->subMinutes(5),
            'duration_minutes'  => 25,
            'room_status_after' => RoomStatus::VacantClean->value,
            'inspection_result' => InspectionResult::Pass,
            'inspected_by'      => User::factory(),
            'inspected_at'      => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'completed_at'      => now()->subMinutes(5),
            'duration_minutes'  => 25,
            'room_status_after' => RoomStatus::VacantDirty->value,
            'inspection_result' => InspectionResult::Fail,
            'inspected_by'      => User::factory(),
            'inspected_at'      => now(),
            'inspection_notes'  => 'Phòng chưa sạch đủ tiêu chuẩn.',
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'completed_at'      => now()->subMinutes(5),
            'duration_minutes'  => 25,
            'room_status_after' => RoomStatus::VacantClean->value,
            'inspection_result' => InspectionResult::Skip,
            'inspected_by'      => User::factory(),
            'inspected_at'      => now(),
            'inspection_notes'  => 'Bỏ qua kiểm tra theo chỉ định của quản lý.',
        ]);
    }
}
