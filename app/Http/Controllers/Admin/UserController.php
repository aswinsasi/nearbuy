<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\ProductRequest;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Admin user management controller.
 */
class UserController extends Controller
{
    /**
     * List all users.
     */
    public function index(Request $request)
    {
        $query = User::with('shop');

        // Filter by type
        if ($request->filled('type')) {
            if ($request->type === 'customer') {
                $query->whereDoesntHave('shop');
            } elseif ($request->type === 'shop_owner') {
                $query->whereHas('shop');
            }
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
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
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->latest()->paginate(20)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show user details.
     */
    public function show(User $user)
    {
        $user->load(['shop.offers', 'shop.responses']);

        $requests = ProductRequest::where('user_id', $user->id)
            ->with('responses')
            ->latest()
            ->take(10)
            ->get();

        $agreements = Agreement::where('from_user_id', $user->id)
            ->orWhere('to_user_id', $user->id)
            ->orWhere('to_phone', $user->phone)
            ->latest()
            ->take(10)
            ->get();

        $stats = [
            'total_requests' => ProductRequest::where('user_id', $user->id)->count(),
            'total_responses' => $user->shop ? $user->shop->responses()->count() : 0,
            'total_agreements' => Agreement::where('from_user_id', $user->id)
                ->orWhere('to_user_id', $user->id)
                ->orWhere('to_phone', $user->phone)
                ->count(),
        ];

        return view('admin.users.show', compact('user', 'requests', 'agreements', 'stats'));
    }

    /**
     * Toggle user active status.
     */
    public function toggleStatus(User $user)
    {
        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'suspended';

        return back()->with('success', "User {$status} successfully.");
    }

    /**
     * Delete user (soft delete or permanent).
     */
    public function destroy(User $user)
    {
        // Check if user has important data
        $hasAgreements = Agreement::where('from_user_id', $user->id)
            ->orWhere('to_user_id', $user->id)
            ->orWhere('to_phone', $user->phone)
            ->exists();

        if ($hasAgreements) {
            // Soft delete / deactivate
            $user->update(['is_active' => false]);
            return back()->with('success', 'User deactivated (has existing agreements).');
        }

        // Delete user
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted successfully.');
    }
}