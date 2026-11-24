<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;

class TicketController extends Controller
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
            'search'    => 'sometimes|nullable|string|max:255',
        ]);

        $query = Ticket::query()
            ->with('creator:id,name,email,role')
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

        if ($request->filled('search')) {
            $term = $this->escapeLike(trim($request->search), '=');
            $pattern = "%{$term}%";
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("subject LIKE ? ESCAPE '='", [$pattern])
                  ->orWhereRaw("description LIKE ? ESCAPE '='", [$pattern]);
            });
        }

        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $perPage = min((int) $request->input('per_page', 25), 100);

        return response()->json($query->paginate($perPage)->withQueryString());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'     => 'required|string|max:255',
            'category'    => 'required|string|in:bug,billing,feature,other',
            'priority'    => 'sometimes|nullable|string|in:low,medium,high',
            'description' => 'required|string|max:5000',
        ]);

        $ticket = Ticket::create([
            'created_by_user_id' => $request->user()->id,
            'subject'            => $validated['subject'],
            'category'           => $validated['category'],
            'priority'           => $validated['priority'] ?? 'medium',
            'status'             => 'open',
            'description'        => $validated['description'],
        ]);

        return response()->json([
            'message' => 'Ticket created successfully',
            'ticket'  => $ticket->load('creator:id,name,email,role')->loadCount('messages'),
        ], 201);
    }

    public function show(Ticket $ticket)
    {
        $ticket->load([
            'creator:id,name,email,role',
            'messages.user:id,name,email,role',
        ])->loadCount('messages');

        return response()->json($ticket);
    }

    public function storeMessage(Request $request, Ticket $ticket)
    {
        if ($ticket->status === 'closed') {
            return response()->json([
                'message' => 'Cannot reply to a closed ticket.',
            ], 422);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = $ticket->messages()->create([
            'user_id'           => $request->user()->id,
            'body'              => $validated['body'],
            'is_platform_reply' => false,
        ]);

        return response()->json([
            'message'        => 'Message sent successfully',
            'ticket_message' => $message->load('user:id,name,email,role'),
        ], 201);
    }

    public function close(Ticket $ticket)
    {
        $ticket->update(['status' => 'closed']);

        return response()->json([
            'message' => 'Ticket closed successfully',
            'ticket'  => $ticket->fresh(['creator:id,name,email,role'])->loadCount('messages'),
        ]);
    }
}
