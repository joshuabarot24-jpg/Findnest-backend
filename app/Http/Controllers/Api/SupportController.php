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

        // One pending override request at a time per student
        if ($isOverrideRequest && $user && SupportMessage::where('user_id', $user->id)
                ->where('is_override_request', true)
                ->where('override_status', 'pending')
                ->exists()) {
            return response()->json(['message' => 'You already have a pending override request. Please wait for the Guidance Office to review it.'], 422);
        }

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
            'password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Approving grants the override, so it needs the admin's own password
        if ($request->decision === 'approve' && !\Illuminate\Support\Facades\Hash::check((string) $request->password, $request->user()->password)) {
            return response()->json(['message' => 'Incorrect password. The request was not approved.'], 422);
        }

        $message->update([
            'override_status' => $request->decision === 'approve' ? 'approved' : 'denied',
            'status' => 'responded',
        ]);

        $student = \App\Models\User::find($message->user_id);
        if ($student) {
            if ($request->decision === 'approve') {
                $student->forceFill([
                    'manual_override_granted' => true,
                    'override_expires_at' => now()->addHours(24),
                ])->save();
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

    private const STARTED_TEXT = 'The Guidance Office started this conversation.';

    // Admin: one row per student, newest activity first. Each row has the shape of a message row,
    // and its id is the student's latest message (the target of a reply)
    public function threads()
    {
        $messages = SupportMessage::with('user:id,name,email,lrn,education_level,trust_score,profile_photo_url')
            ->whereNotNull('user_id')
            ->orderBy('created_at')
            ->get();
        $repliesByMsg = SupportReply::whereIn('support_message_id', $messages->pluck('id'))
            ->orderBy('created_at')->get()->groupBy('support_message_id');

        $rows = [];
        foreach ($messages->groupBy('user_id') as $group) {
            $latest = $group->last();
            $items = [];
            foreach ($group as $m) {
                if ($m->message !== self::STARTED_TEXT) {
                    $items[] = ['at' => $m->created_at, 'sender' => 'student', 'text' => $m->message];
                }
                foreach ($repliesByMsg->get($m->id, collect()) as $r) {
                    $items[] = ['at' => $r->created_at, 'sender' => $r->sender_type === 'admin' ? 'admin' : 'student', 'text' => $r->message];
                }
            }
            usort($items, fn ($a, $b) => $a['at']->timestamp <=> $b['at']->timestamp);
            $last = $items ? end($items) : ['at' => $latest->created_at, 'sender' => 'admin', 'text' => $latest->message];

            $unread = $group->where('status', 'new')->count();
            $pending = $group->filter(fn ($m) => $m->is_override_request && $m->override_status === 'pending')->count();
            $u = $latest->user;

            $rows[] = [
                'id' => $latest->id,
                'name' => $u?->name ?? $latest->name,
                'email' => $u?->email ?? $latest->email,
                'message' => ($last['sender'] === 'admin' ? 'You: ' : '') . \Illuminate\Support\Str::limit($last['text'], 90),
                'status' => $unread > 0 ? 'new' : ($last['sender'] === 'admin' ? 'responded' : 'read'),
                'created_at' => $last['at'],
                'is_override_request' => $pending > 0,
                'override_status' => $pending > 0 ? 'pending' : null,
                'user' => $u ? ['id' => $u->id, 'profile_photo_url' => $u->profile_photo_url] : null,
                'lrn' => $u?->lrn,
                'education_level' => $u?->education_level,
                'trust_score' => $u?->trust_score,
                'unread_count' => $unread,
            ];
        }

        usort($rows, fn ($a, $b) => $b['created_at']->timestamp <=> $a['created_at']->timestamp);

        return response()->json(['messages' => $rows]);
    }

    // Admin: every message and reply of the student who owns this message, oldest first
    public function conversation($id)
    {
        $anchor = SupportMessage::findOrFail($id);
        $messages = $anchor->user_id
            ? SupportMessage::where('user_id', $anchor->user_id)->orderBy('created_at')->get()
            : collect([$anchor]);
        $repliesByMsg = SupportReply::whereIn('support_message_id', $messages->pluck('id'))
            ->orderBy('created_at')->get()->groupBy('support_message_id');

        $items = [];
        foreach ($messages as $m) {
            if ($m->message === self::STARTED_TEXT) {
                $items[] = ['key' => 's' . $m->id, 'kind' => 'system', 'text' => $m->message, 'at' => $m->created_at];
            } else {
                $items[] = [
                    'key' => 'm' . $m->id, 'kind' => 'message', 'message_id' => $m->id, 'sender' => 'student',
                    'text' => $m->message, 'at' => $m->created_at,
                    'is_override_request' => (bool) $m->is_override_request, 'override_status' => $m->override_status,
                ];
            }
            foreach ($repliesByMsg->get($m->id, collect()) as $r) {
                $items[] = [
                    'key' => 'r' . $r->id, 'kind' => 'reply',
                    'sender' => $r->sender_type === 'admin' ? 'admin' : 'student',
                    'text' => $r->message, 'at' => $r->created_at,
                ];
            }
        }
        usort($items, fn ($a, $b) => $a['at']->timestamp <=> $b['at']->timestamp);

        return response()->json(['items' => $items]);
    }

    // Student: the whole conversation with the Guidance Office, oldest first
    public function myConversation(Request $request)
    {
        $messages = SupportMessage::where('user_id', $request->user()->id)->orderBy('created_at')->get();
        $repliesByMsg = SupportReply::whereIn('support_message_id', $messages->pluck('id'))
            ->orderBy('created_at')->get()->groupBy('support_message_id');

        $items = [];
        foreach ($messages as $m) {
            if ($m->message === self::STARTED_TEXT) {
                $items[] = ['key' => 's' . $m->id, 'kind' => 'system', 'text' => self::STARTED_TEXT, 'at' => $m->created_at];
            } else {
                $items[] = [
                    'key' => 'm' . $m->id, 'kind' => 'message', 'sender' => 'student',
                    'text' => $m->message, 'at' => $m->created_at,
                    'is_override_request' => (bool) $m->is_override_request, 'override_status' => $m->override_status,
                ];
            }
            foreach ($repliesByMsg->get($m->id, collect()) as $r) {
                $items[] = [
                    'key' => 'r' . $r->id, 'kind' => 'reply',
                    'sender' => $r->sender_type === 'admin' ? 'admin' : 'student',
                    'text' => $r->message, 'at' => $r->created_at,
                ];
            }
        }
        usort($items, fn ($a, $b) => $a['at']->timestamp <=> $b['at']->timestamp);

        return response()->json(['items' => $items]);
    }

    // Student: send a message to the Guidance Office. It joins the latest conversation, or starts one
    public function sendFromStudent(Request $request)
    {
        $validator = Validator::make($request->all(), ['message' => 'required|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $latest = SupportMessage::where('user_id', $user->id)->orderByDesc('created_at')->first();

        if ($latest) {
            SupportReply::create([
                'support_message_id' => $latest->id,
                'user_id' => $user->id,
                'sender_type' => 'student',
                'message' => $request->message,
            ]);
            $latest->update(['status' => 'new']);
        } else {
            SupportMessage::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'message' => $request->message,
                'status' => 'new',
                'is_override_request' => false,
                'override_status' => null,
            ]);
        }

        return response()->json(['message' => 'Message sent'], 201);
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
        // Opening a student's conversation marks all of that student's new messages as read
        if ($message->user_id) {
            SupportMessage::where('user_id', $message->user_id)->where('status', 'new')->update(['status' => 'read']);
        } else {
            $message->update(['status' => 'read']);
        }
        return response()->json(['message' => 'Marked as read', 'data' => $message->fresh()]);
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
            'message' => 'required|string|max:1000',
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
        if ($senderType === 'student') {
            $message->update(['status' => 'new']);
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
                    ->orWhere('lrn', 'ILIKE', $like);
            });

        // Active accounts only. Change this if your archive column has a different name
        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        } elseif (Schema::hasColumn('users', 'archived_at')) {
            $query->whereNull('archived_at');
        }

        $students = $query->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email', 'lrn', 'profile_photo_url']);

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

        if (!empty($result['escalate'])) {
            $user = $request->user();
            $text = 'Sent from the assistant: ' . trim($request->question);

            // The same question is not sent twice within 5 minutes
            $recent = SupportMessage::where('user_id', $user?->id)
                ->where('message', $text)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->exists();

            if (!$recent) {
                SupportMessage::create([
                    'user_id' => $user?->id,
                    'name' => $user?->name ?? 'Guest',
                    'email' => $user?->email ?? 'unknown@findnest.local',
                    'message' => $text,
                    'status' => 'new',
                    'is_override_request' => false,
                    'override_status' => null,
                ]);
            }

            return response()->json([
                'answer' => "I couldn't answer that, so I sent your question to the Guidance Office. You will see their reply in Support and get a notification.",
                'escalated' => true,
            ]);
        }

        return response()->json(['answer' => $result['answer']]);
    }
}
