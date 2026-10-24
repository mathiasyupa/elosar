<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Appointment;
use Illuminate\Auth\Access\HandlesAuthorization;

class AppointmentPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Appointment');
    }

    public function view(AuthUser $authUser, Appointment $appointment): bool
    {
        return $authUser->can('View:Appointment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Appointment');
    }

    public function update(AuthUser $authUser, Appointment $appointment): bool
    {
        return $authUser->can('Update:Appointment');
    }

    public function delete(AuthUser $authUser, Appointment $appointment): bool
    {
        return $authUser->can('Delete:Appointment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Appointment');
    }

}