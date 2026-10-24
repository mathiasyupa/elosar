<?php

namespace App\Filament\SuperAdmin\Support;

use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;

class CommercialDisplay
{
    public static function plan(?WorkspaceEntitlement $entitlement): string
    {
        return match ($entitlement?->plan_code) {
            WorkspaceEntitlement::PLAN_PERSONAL => 'Consultorio personal',
            WorkspaceEntitlement::PLAN_CLINIC => 'Clínica',
            WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED => 'Clínica ilimitada',
            default => 'Sin plan',
        };
    }

    public static function access(Workspace $workspace): string
    {
        if ($workspace->isSuspended()) {
            return 'Suspendido';
        }

        $entitlement = $workspace->entitlement;
        if (app(WorkspaceEntitlementService::class)->effectiveAccess($workspace) === WorkspaceEntitlementService::ACCESS_READ_ONLY) {
            return 'Solo lectura';
        }

        return $entitlement?->state === WorkspaceEntitlement::STATE_TRIAL ? 'Prueba activa' : 'Acceso activo';
    }

    public static function accessColor(Workspace $workspace): string
    {
        return match (self::access($workspace)) {
            'Suspendido', 'Solo lectura' => 'danger',
            'Prueba activa' => 'warning',
            default => 'success',
        };
    }

    public static function accessNote(Workspace $workspace): ?string
    {
        $entitlement = $workspace->entitlement;

        return $entitlement?->state === WorkspaceEntitlement::STATE_ACTIVE
            && $entitlement->access_ends_at?->isPast()
                ? 'Plazo vencido; revisar acceso'
                : null;
    }

    public static function seats(Workspace $workspace): string
    {
        $entitlement = $workspace->entitlement;
        if (! $workspace->isClinic() || ! $entitlement) {
            return '—';
        }

        $seats = app(WorkspaceEntitlementService::class)->seatSummary($workspace);

        return $entitlement->isUnlimited()
            ? "{$seats['active_doctors']} / Sin límite"
            : "{$seats['active_doctors']} / {$seats['included']}";
    }

    public static function price(WorkspaceEntitlement $entitlement): string
    {
        return $entitlement->isUnlimited() ? 'Tarifa manual' : 'USD '.$entitlement->monthlyPriceUsd();
    }

    public static function deadline(WorkspaceEntitlement $entitlement): ?\DateTimeInterface
    {
        return $entitlement->state === WorkspaceEntitlement::STATE_TRIAL
            ? $entitlement->trial_ends_at
            : $entitlement->access_ends_at;
    }
}
