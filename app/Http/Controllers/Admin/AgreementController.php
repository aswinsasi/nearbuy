<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgreementStatus;
use App\Http\Controllers\Controller;
use App\Models\Agreement;
use Illuminate\Http\Request;

/**
 * Admin agreement management controller.
 */
class AgreementController extends Controller
{
    /**
     * List all agreements.
     */
    public function index(Request $request)
    {
        $query = Agreement::with(['creator']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Filter by amount range
        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->max_amount);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('agreement_number', 'like', "%{$search}%")
                    ->orWhere('to_name', 'like', "%{$search}%")
                    ->orWhere('to_phone', 'like', "%{$search}%")
                    ->orWhereHas('creator', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $agreements = $query->latest()->paginate(20)->withQueryString();

        $statuses = AgreementStatus::cases();

        $stats = [
            'total_value' => Agreement::where('status', AgreementStatus::CONFIRMED)->sum('amount'),
            'pending_count' => Agreement::where('status', AgreementStatus::PENDING)->count(),
            'disputed_count' => Agreement::where('status', AgreementStatus::DISPUTED)->count(),
        ];

        return view('admin.agreements.index', compact('agreements', 'statuses', 'stats'));
    }

    /**
     * Show agreement details.
     */
    public function show(Agreement $agreement)
    {
        $agreement->load(['creator', 'counterpartyUser']);

        return view('admin.agreements.show', compact('agreement'));
    }

    /**
     * Download agreement PDF.
     */
    public function downloadPdf(Agreement $agreement)
    {
        if (!$agreement->pdf_url) {
            return back()->with('error', 'PDF not available for this agreement.');
        }

        return redirect($agreement->pdf_url);
    }

    /**
     * Resolve disputed agreement.
     */
    public function resolveDispute(Request $request, Agreement $agreement)
    {
        $request->validate([
            'resolution' => 'required|in:confirm,reject,cancel',
            'notes' => 'nullable|string|max:500',
        ]);

        $newStatus = match ($request->resolution) {
            'confirm' => AgreementStatus::CONFIRMED,
            'reject' => AgreementStatus::REJECTED,
            'cancel' => AgreementStatus::CANCELLED,
        };

        $agreement->update([
            'status' => $newStatus,
            'admin_notes' => $request->notes,
            'resolved_at' => now(),
            'resolved_by' => auth('admin')->id(),
        ]);

        return back()->with('success', 'Dispute resolved successfully.');
    }

    /**
     * Cancel agreement (admin action).
     */
    public function cancel(Agreement $agreement)
    {
        if ($agreement->status === AgreementStatus::COMPLETED) {
            return back()->with('error', 'Completed agreements cannot be cancelled.');
        }

        $agreement->update([
            'status' => AgreementStatus::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => auth('admin')->id(),
        ]);

        return back()->with('success', 'Agreement cancelled successfully.');
    }
}