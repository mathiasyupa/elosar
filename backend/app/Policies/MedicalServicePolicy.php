<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\MedicalService;
use Illuminate\Auth\Access\HandlesAuthorization;

class MedicalServicePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MedicalService');
    }

    public function view(AuthUser $authUser, MedicalService $medicalService): bool
    {
        return $authUser->can('View:MedicalService');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MedicalService');
    }

    public function update(AuthUser $authUser, MedicalService $medicalService): bool
    {
        return $authUser->can('Update:MedicalService');
    }

    public function delete(AuthUser $authUser, MedicalService $medicalService): bool
    {
        return $authUser->can('Delete:MedicalService');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MedicalService');
    }

}