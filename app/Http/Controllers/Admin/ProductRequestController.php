<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductRequest;
use Illuminate\Http\Request;

/**
 * Admin product request management controller.
 */
class ProductRequestController extends Controller
{
    /**
     * List all product requests.
     */
    public function index(Request $request)
    {
        $query = ProductRequest::with(['user', 'responses.shop']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('request_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $requests = $query->withCount('responses')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $statuses = RequestStatus::cases();

        return view('admin.requests.index', compact('requests', 'statuses'));
    }

    /**
     * Show request details.
     */
    public function show(ProductRequest $productRequest)
    {
        $productRequest->load([
            'user',
            'responses.shop.owner',
        ]);

        return view('admin.requests.show', compact('productRequest'));
    }

    /**
     * Manually close a request.
     */
    public function close(ProductRequest $productRequest)
    {
        if (!in_array($productRequest->status, [RequestStatus::OPEN, RequestStatus::COLLECTING])) {
            return back()->with('error', 'Request cannot be closed.');
        }

        $productRequest->update([
            'status' => RequestStatus::CLOSED,
        ]);

        return back()->with('success', 'Request closed successfully.');
    }

    /**
     * Delete request (admin only for cleanup).
     */
    public function destroy(ProductRequest $productRequest)
    {
        // Delete responses first
        $productRequest->responses()->delete();

        // Delete request
        $productRequest->delete();

        return redirect()->route('admin.requests.index')
            ->with('success', 'Request deleted successfully.');
    }
}