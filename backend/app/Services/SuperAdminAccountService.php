<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/** Creates complete clinical accounts from the platform panel. */
class SuperAdminAccountService
{
    public function __construct(
        private readonly TrialCampaignService $campaigns,
        private readonly ClinicService $clinics,
    ) {}

    /**
     * @param array{name:string,email:string,id_number:string,city:string,phone:string,specialty_id:int,clinic_name?:?string,clinic_id?:?int} $data
     * @return array{user:User,temporary_password:string}
     */
    public function createDoctor(array $data): array
    {
        $result = DB::transaction(function () use ($data): array {
            [$user, $password] = $this->newUser($data['name'], $data['email'], 'doctor');

            // Doctor::created provisions the personal workspace, so the grant
            // must exist before creating the doctor.
            $this->campaigns->grantForDoctor($user);

            $doctor = Doctor::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'id_number' => $data['id_number'],
                'city' => $data['city'],
                'phone' => $data['phone'],
                'specialty_id' => $data['specialty_id'],
            ]);
            $doctor->specialties()->sync([$data['specialty_id'] => ['is_primary' => true]]);

            if (filled($data['clinic_name'] ?? null)) {
                $this->clinics->createClinic($doctor, $data['clinic_name']);
            } elseif (isset($data['clinic_id'])) {
                $clinic = Workspace::query()->where('type', 'clinic')->findOrFail($data['clinic_id']);
                $this->clinics->attachDoctor($clinic, $doctor);
            }

            return ['user' => $user, 'temporary_password' => $password];
        });

        event(new Registered($result['user']));

        return $result;
    }

    /**
     * @param array{name:string,email:string,clinic_name:string,phone?:?string} $data
     * @return array{user:User,temporary_password:string}
     */
    public function createClinicOwner(array $data): array
    {
        $result = DB::transaction(function () use ($data): array {
            [$user, $password] = $this->newUser($data['name'], $data['email'], 'staff');
            StaffProfile::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'job_title' => 'Administración',
            ]);

            $this->campaigns->grantForClinicAdmin($user);
            $this->clinics->createClinicForUser($user->fresh('staffProfile'), $data['clinic_name']);

            return ['user' => $user, 'temporary_password' => $password];
        });

        event(new Registered($result['user']));

        return $result;
    }

    /**
     * @param array{name:string,email:string,clinic_id:int,role:string,phone?:?string} $data
     * @return array{user:User,temporary_password:string}
     */
    public function createStaff(array $data): array
    {
        if (! in_array($data['role'], array_diff(WorkspaceMember::ROLES, ['doctor']), true)) {
            throw new LogicException('A staff account cannot hold the doctor role.');
        }

        $result = DB::transaction(function () use ($data): array {
            $clinic = Workspace::query()->where('type', 'clinic')->findOrFail($data['clinic_id']);
            [$user, $password] = $this->newUser($data['name'], $data['email'], 'staff');
            StaffProfile::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'job_title' => $data['role'],
            ]);
            $this->clinics->attachMember($clinic, $user->fresh('staffProfile'), $data['role']);

            return ['user' => $user, 'temporary_password' => $password];
        });

        event(new Registered($result['user']));

        return $result;
    }

    /** @return array{User,string} */
    private function newUser(string $name, string $email, string $type): array
    {
        $password = Str::password(24);

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'account_type' => $type,
            'password' => Hash::make($password),
            'must_change_password' => true,
        ]);

        return [$user, $password];
    }
}
