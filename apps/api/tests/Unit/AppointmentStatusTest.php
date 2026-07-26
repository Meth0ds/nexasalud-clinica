<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use PHPUnit\Framework\TestCase;

class AppointmentStatusTest extends TestCase
{
    public function test_only_explicit_appointment_transitions_are_allowed(): void
    {
        $this->assertTrue(AppointmentStatus::Scheduled->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::CheckedIn));
        $this->assertTrue(AppointmentStatus::InProgress->canTransitionTo(AppointmentStatus::Completed));
        $this->assertFalse(AppointmentStatus::Scheduled->canTransitionTo(AppointmentStatus::Completed));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Scheduled));
        $this->assertFalse(AppointmentStatus::Cancelled->blocksAvailability());
        $this->assertTrue(AppointmentStatus::Confirmed->blocksAvailability());
    }
}
