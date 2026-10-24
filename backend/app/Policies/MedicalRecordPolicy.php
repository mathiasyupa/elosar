<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\MedicalRecord;
use Illuminate\Auth\Access\HandlesAuthorization;

class MedicalRecordPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MedicalRecord');
    }

    public function view(AuthUser $authUser, MedicalRecord $medicalRecord): bool
    {
        return $authUser->can('View:MedicalRecord');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MedicalRecord');
    }

    public function update(AuthUser $authUser, MedicalRecord $medicalRecord): bool
    {
        return $authUser->can('Update:MedicalRecord');
    }

    public function delete(AuthUser $authUser, MedicalRecord $medicalRecord): bool
    {
        return $authUser->can('Delete:MedicalRecord');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MedicalRecord');
    }

}