<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportReply;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use App\Models\AuditLog;

class SupportController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $isOverrideRequest = $request->boolean('is_override_request');

        $message = SupportMessage::create([
            'user_id' => $user?->id,
            'name' => $user?->name ?? 'Guest',
            'email' => $user?->email ?? 'unknown@findnest.local',
            'message' => $request->message,
            'status' => 'new',
            'is_override_request' => $isOverrideRequest,
            'override_status' => $isOverrideRequest ? 'pending' : null,
        ]);

        return response()->json([
            'message' => 'Support message sent successfully',
            'data' => $message,
        ], 201);
    }

    public function resolveOverrideRequest(Request $request, $id)
    {
        $message = SupportMessage::findOrFail($id);

        if (!$message->is_override_request) {
            return response()->json(['message' => 'This message is not an override request.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,deny',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message->update([
            'override_status' => $request->decision === 'approve' ? 'approved' : 'denied',
            'status' => 'responded',
        ]);

        $student = \App\Models\User::find($message->user_id);
        if ($student) {
            if ($request->decision === 'approve') {
                $student->update(['manual_override_granted' => true]);
            }

            Notification::create([
                'user_id' => $student->id,
                'match_id' => null,
                'title' => $request->decision === 'approve' ? 'Manual Override Approved' : 'Manual Override Request Denied',
                'message' => $request->decision === 'approve'
                    ? 'Your request to manually report a lost item without a clear photo has been approved. You may now submit your report, filling in all details yourself.'
                    : 'Your manual override request was reviewed and was not approved. Please try uploading a clearer photo.',
                'type' => 'status',
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Manual Override Request ' . ($request->decision === 'approve' ? 'Approved' : 'Denied'),
            'target_type' => 'support_messages',
            'target_id' => $message->id,
            'details' => 'Admin ' . $request->decision . 'd manual AI override request from ' . $message->name,
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Override request ' . $request->decision . 'd successfully', 'data' => $message]);
    }

    public function index()
    {
        // Loads only the sender's id and photo, so nothing else about the user is exposed
        $messages = SupportMessage::with('user:id,profile_photo_url')->orderBy('created_at', 'desc')->get();
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
            'original' => $message->load('user:id,profile_photo_url'),
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

// Admin: find active students by name, email, or school ID
    public function searchStudents(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['students' => []]);
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        $query = \App\Models\User::where('role', 'student')
            ->where(function ($w) use ($like) {
                $w->where('name', 'ILIKE', $like)
                    ->orWhere('email', 'ILIKE', $like)
                    ->orWhere('school_id', 'ILIKE', $like);
            });

        // Active accounts only. Change this if your archive column has a different name
        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        } elseif (Schema::hasColumn('users', 'archived_at')) {
            $query->whereNull('archived_at');
        }

        $students = $query->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email', 'school_id', 'profile_photo_url']);

        return response()->json(['students' => $students]);
    }

    // Admin: send a message to a student. Reuses their latest thread, or starts a new one
    public function composeMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|integer',
            'message' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $student = \App\Models\User::where('id', $request->student_id)->where('role', 'student')->first();
        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 422);
        }

        $thread = SupportMessage::where('user_id', $student->id)->orderByDesc('created_at')->first();
        $created = false;

        if (!$thread) {
            $thread = SupportMessage::create([
                'user_id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'message' => 'The Guidance Office started this conversation.',
                'status' => 'responded',
                'is_override_request' => false,
                'override_status' => null,
            ]);
            $created = true;
        } else {
            $thread->update(['status' => 'responded']);
        }

        $reply = SupportReply::create([
            'support_message_id' => $thread->id,
            'user_id' => $request->user()->id,
            'sender_type' => 'admin',
            'message' => $request->message,
        ]);

        Notification::create([
            'user_id' => $student->id,
            'match_id' => null,
            'title' => 'New Message from the Guidance Office',
            'message' => 'The Guidance Office sent you a message. Open Support to read it.',
            'type' => 'support',
            'is_read' => false,
            'sent_at' => Carbon::now(),
        ]);

        if ($student->fcm_token) {
            (new \App\Services\FcmService())->sendToUser(
                $student->fcm_token,
                'New Message from the Guidance Office',
                'Open FindNest to read it.',
                ['type' => 'support']
            );
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'Support Message Sent',
            'target_type' => 'support_messages',
            'target_id' => $thread->id,
            'details' => 'Admin sent a direct message to ' . $student->name,
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Message sent',
            'thread' => $thread,
            'reply' => $reply,
            'created' => $created,
        ], 201);
    }

    public function askChatbot(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'question' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $chatbot = new \App\Services\FaqChatbotService();
        $result = $chatbot->answerQuestion($request->question);

        return response()->json(['answer' => $result['answer']]);
    }
}
