<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\Ticket;
use Illuminate\Http\Request;

class PlatformTicketController extends Controller
{
    private function escapeLike(string $value, string $char = '='): string
    {
        return str_replace(
            [$char, '%', '_'],
            [$char.$char, $char.'%', $char.'_'],
            $value
        );
    }

    public function index(Request $request)
    {
        $request->validate([
            'page'      => 'sometimes|integer|min:1',
            'per_page'  => 'sometimes|integer|min:1',
            'status'    => 'sometimes|nullable|string|in:open,in_progress,resolved,closed',
            'priority'  => 'sometimes|nullable|string|in:low,medium,high',
            'category'  => 'sometimes|nullable|string|in:bug,billing,feature,other',
            'spa_id'    => 'sometimes|nullable|integer',
            'search'    => 'sometimes|nullable|string|max:255',
        ]);

        $query = Ticket::withoutGlobalScopes()
            ->with([
                'spa:id,name,slug',
                'creator:id,name,email,role',
            ])
            ->withCount('messages');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('spa_id')) {
            $query->where('spa_id', (int) $request->spa_id);
        }

        if ($request->filled('search')) {
            $term = $this->escapeLike(trim($request->search), '=');
            $pattern = "%{$term}%";
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("subject LIKE ? ESCAPE '='", [$pattern])
                  ->orWhereRaw("description LIKE ? ESCAPE '='", [$pattern])
                  ->orWhereHas('spa', function ($sq) use ($pattern) {
                      $sq->whereRaw("name LIKE ? ESCAPE '='", [$pattern]);
                  });
            });
        }

        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $perPage = min((int) $request->input('per_page', 25), 100);

        return response()->json($query->paginate($perPage)->withQueryString());
    }

    public function show($id)
    {
        $ticket = Ticket::withoutGlobalScopes()
            ->with([
                'spa:id,name,slug',
                'creator:id,name,email,role',
                'messages.user:id,name,email,role',
            ])
            ->withCount('messages')
            ->find($id);

        if (! $ticket) {
            abort(404, 'Ticket not found.');
        }

        return response()->json($ticket);
    }

    public function update(Request $request, $id)
    {
        $ticket = Ticket::withoutGlobalScopes()->find($id);

        if (! $ticket) {
            abort(404, 'Ticket not found.');
        }

        $validated = $request->validate([
            'status'   => 'sometimes|string|in:open,in_progress,resolved,closed',
            'priority' => 'sometimes|string|in:low,medium,high',
        ]);

        if (isset($validated['status'])) {
            if ($validated['status'] === 'resolved' && $ticket->status !== 'resolved') {
                $ticket->resolved_at = now();
            } elseif (in_array($validated['status'], ['open', 'in_progress'], true)) {
                $ticket->resolved_at = null;
            }
            $ticket->status = $validated['status'];
        }

        if (isset($validated['priority'])) {
            $ticket->priority = $validated['priority'];
        }

        $ticket->save();

        PlatformAuditLog::create([
            'actor_user_id' => $request->user()->id,
            'action'        => 'ticket.updated',
            'subject_type'  => 'Ticket',
            'subject_id'    => $ticket->id,
            'spa_id'        => $ticket->spa_id,
            'meta'          => [
                'status'   => $ticket->status,
                'priority' => $ticket->priority,
            ],
            'ip'            => $request->ip(),
            'created_at'    => now(),
        ]);

        return response()->json([
            'message' => 'Ticket updated successfully',
            'ticket'  => $ticket->fresh(['spa:id,name,slug', 'creator:id,name,email,role'])->loadCount('messages'),
        ]);
    }

    public function storeMessage(Request $request, $id)
    {
        $ticket = Ticket::withoutGlobalScopes()->find($id);

        if (! $ticket) {
            abort(404, 'Ticket not found.');
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = $ticket->messages()->create([
            'user_id'           => $request->user()->id,
            'body'              => $validated['body'],
            'is_platform_reply' => true,
        ]);

        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        PlatformAuditLog::create([
            'actor_user_id' => $request->user()->id,
            'action'        => 'ticket.replied',
            'subject_type'  => 'Ticket',
            'subject_id'    => $ticket->id,
            'spa_id'        => $ticket->spa_id,
            'meta'          => [
                'message_id' => $message->id,
            ],
            'ip'            => $request->ip(),
            'created_at'    => now(),
        ]);

        return response()->json([
            'message'        => 'Reply sent successfully',
            'ticket_message' => $message->load('user:id,name,email,role'),
            'ticket'         => $ticket->fresh(['spa:id,name,slug', 'creator:id,name,email,role'])->loadCount('messages'),
        ], 201);
    }
}
