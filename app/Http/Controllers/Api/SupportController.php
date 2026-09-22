<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportReply;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class SupportController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = SupportMessage::create([
            'user_id' => $request->user()?->id,
            'name' => $request->name,
            'email' => $request->email,
            'message' => $request->message,
            'status' => 'new',
        ]);

        return response()->json([
            'message' => 'Support message sent successfully',
            'data' => $message,
        ], 201);
    }

    public function index()
    {
        $messages = SupportMessage::orderBy('created_at', 'desc')->get();
        return response()->json(['messages' => $messages]);
    }

    public function myMessages(Request $request)
    {
        $messages = SupportMessage::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['messages' => $messages]);
    }

    public function markAsRead($id)
    {
        $message = SupportMessage::findOrFail($id);
        $message->update(['status' => 'read']);
        return response()->json(['message' => 'Marked as read', 'data' => $message]);
    }

    public function getThread(Request $request, $id)
    {
        $message = SupportMessage::findOrFail($id);

        if ($request->user()->role === 'student' && $message->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $replies = SupportReply::where('support_message_id', $id)
            ->with('user:id,name')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'original' => $message,
            'replies' => $replies,
        ]);
    }

    public function reply(Request $request, $id)
    {
        $message = SupportMessage::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $senderType = $request->user()->role === 'student' ? 'student' : 'admin';

        if ($senderType === 'student' && $message->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reply = SupportReply::create([
            'support_message_id' => $message->id,
            'user_id' => $request->user()->id,
            'sender_type' => $senderType,
            'message' => $request->message,
        ]);

        if ($senderType === 'admin' && $message->user_id) {
            Notification::create([
                'user_id' => $message->user_id,
                'match_id' => null,
                'title' => 'New Reply from Support',
                'message' => 'The Guidance Office replied to your support message.',
                'type' => 'support',
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);
            $message->update(['status' => 'responded']);
        }

        return response()->json(['message' => 'Reply sent successfully', 'data' => $reply], 201);
    }
}
