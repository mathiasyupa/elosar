<?php

namespace App\Filament\Pages;

use App\Models\Appointment;
use App\Support\ActiveWorkspace;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Appointments report by period.
 *
 * Shows attendance stats, no-shows, distribution by status,
 * appointment type and per-doctor breakdown.
 */
class AppointmentsReport extends Page
{
    protected string $view = 'filament.pages.appointments-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Appointments';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $title = 'Appointments Report';

    protected static ?int $navigationSort = 2;

    public string $dateFrom = '';
    public string $dateTo   = '';

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->endOfMonth()->format('Y-m-d');
    }

    /** Total appointments in the period. */
    public function getTotalAppointments(): int
    {
        return $this->appointmentsQuery()->count();
    }

    /**
     * Count by status (keys are English display labels).
     *
     * @return array<string,int>
     */
    public function getByStatus(): array
    {
        return $this->appointmentsQuery()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($total, $status) => [
                match ($status) {
                    'scheduled'  => 'Scheduled',
                    'completed'  => 'Completed',
                    'cancelled'  => 'Cancelled',
                    default      => $status,
                } => (int) $total,
            ])
            ->all();
    }

    /**
     * No-shows: "scheduled" appointments with a date in the past.
     */
    public function getNoShows(): int
    {
        return $this->appointmentsQuery()
            ->where('status', 'scheduled')
            ->where('appointment_date', '<', now())
            ->count();
    }

    /**
     * Count by appointment type (keys are English display labels).
     *
     * @return array<string,int>
     */
    public function getByType(): array
    {
        return $this->appointmentsQuery()
            ->whereNotNull('type')
            ->select('type', DB::raw('COUNT(*) as total'))
            ->groupBy('type')
            ->pluck('total', 'type')
            ->mapWithKeys(fn ($total, $type) => [
                match ($type) {
                    'first_visit'   => 'First visit',
                    'follow_up'     => 'Follow-up',
                    'procedure'     => 'Procedure',
                    'emergency'     => 'Emergency',
                    'telemedicine'  => 'Telemedicine',
                    default         => $type,
                } => (int) $total,
            ])
            ->all();
    }

    /**
     * Per-doctor breakdown: name => ['completed' => n, 'cancelled' => n, 'total' => n]
     *
     * @return array<string,array<string,int>>
     */
    public function getByDoctor(): array
    {
        $rows = $this->appointmentsQuery()
            ->join('doctors', 'appointments.doctor_id', '=', 'doctors.id')
            ->select(
                'doctors.name as doctor_name',
                'appointments.status',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('doctors.name', 'appointments.status')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $name = $row->doctor_name;
            if (! isset($result[$name])) {
                $result[$name] = ['completed' => 0, 'cancelled' => 0, 'scheduled' => 0, 'total' => 0];
            }
            $result[$name][$row->status] = (int) $row->total;
            $result[$name]['total'] += (int) $row->total;
        }

        // Sort by total, descending
        uasort($result, fn ($a, $b) => $b['total'] - $a['total']);

        return $result;
    }

    /** Base query of appointments for the active workspace within the date range. */
    private function appointmentsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        $query = Appointment::query();

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        if ($this->dateFrom) {
            $query->whereDate('appointment_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('appointment_date', '<=', $this->dateTo);
        }

        return $query;
    }
}
