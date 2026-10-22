<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Instructors known to the backend. Who advises whom is resolved by
 * AURA-AGENT itself from the Activity API, so it isn't exposed here.
 */
class DirectoryController extends Controller
{
    /**
     * GET /api/instructors — admins only. Lists instructors who have logged in
     * at least once (roles come from their YRU Passport claims).
     */
    public function instructors(): JsonResponse
    {
        return response()->json([
            'data' => User::where('role', UserRole::Instructor)
                ->orderBy('name')
                ->get(['id', 'username', 'name', 'email', 'last_login_at']),
        ]);
    }
}
