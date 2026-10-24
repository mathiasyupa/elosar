<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Doctor;
use Illuminate\Auth\Access\HandlesAuthorization;

class DoctorPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Doctor');
    }

    public function view(AuthUser $authUser, Doctor $doctor): bool
    {
        return $authUser->can('View:Doctor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Doctor');
    }

    public function update(AuthUser $authUser, Doctor $doctor): bool
    {
        return $authUser->can('Update:Doctor');
    }

    public function delete(AuthUser $authUser, Doctor $doctor): bool
    {
        return $authUser->can('Delete:Doctor');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Doctor');
    }

}