<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for admin authentication.
 */
class AdminAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        // Check if authenticated as admin
        if (!Auth::guard('admin')->check()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            return redirect()->route('admin.login');
        }

        $admin = Auth::guard('admin')->user();

        // Check if admin is active
        if (!$admin->is_active) {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')
                ->with('error', 'Your account has been deactivated.');
        }

        // Check role if specified
        if ($role && !$admin->hasRole($role) && !$admin->isSuperAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}