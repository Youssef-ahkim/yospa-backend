<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;

class PlatformAuditController extends Controller
{
    public function index(Request $request)
    {
        $limit = (int) $request->input('limit', 50);
        if ($limit <= 0) {
            $limit = 50;
        }

        $logs = PlatformAuditLog::with([
            'actor:id,name,email',
            'spa:id,name,slug',
        ])
            ->latest('id')
            ->limit($limit)
            ->get();

        return response()->json($logs);
    }
}
