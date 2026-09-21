<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with(['user:id,name', 'project:id,name'])->latest();

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Vrijeme', 'Korisnik', 'Metoda', 'Ruta', 'Projekat', 'Objekat', 'Status', 'IP']);
            $query->chunkById(500, function ($logs) use ($output): void {
                foreach ($logs as $log) {
                    fputcsv($output, [
                        $log->created_at?->toIso8601String(), $log->user?->name, $log->method,
                        $log->route_name ?: $log->path, $log->project?->name,
                        $log->subject_type ? $log->subject_type.' #'.$log->subject_id : null,
                        $log->status_code, $log->ip_address,
                    ]);
                }
            }, 'id', 'id');
            fclose($output);
        }, 'ftth-audit-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function filtered(Request $request)
    {
        return ActivityLog::query()
            ->when($request->filled('audit_user'), fn ($query) => $query->where('user_id', $request->integer('audit_user')))
            ->when($request->filled('audit_project'), fn ($query) => $query->where('project_id', $request->integer('audit_project')))
            ->when($request->filled('audit_method'), fn ($query) => $query->where('method', $request->string('audit_method')->upper()->toString()))
            ->when($request->filled('audit_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('audit_from')))
            ->when($request->filled('audit_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('audit_to')));
    }
}
