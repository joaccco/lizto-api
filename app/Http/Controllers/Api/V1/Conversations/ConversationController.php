<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Domain\Offers\Events\ContactInfoDetected;
use App\Domain\Offers\Events\MessageSent;
use App\Domain\Offers\Services\ContactInfoGuard;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\MessageModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function __construct(protected ContactInfoGuard $contactGuard) {}

    public function messages(string $id): JsonResponse
    {
        $conversation = ConversationModel::where('uuid', $id)->firstOrFail();
        $messages = $conversation->messages()->with('sender')->get();

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->uuid,
                'messages' => $messages->map(fn($m) => [
                    'id' => $m->uuid,
                    'sender_id' => $m->sender_id,
                    'sender_name' => $m->sender?->name ?? 'Usuario',
                    'content' => $m->content,
                    'created_at' => $m->created_at?->toISOString(),
                ]),
            ],
        ]);
    }

    public function sendMessage(Request $request, string $id): JsonResponse
    {
        $conversation = ConversationModel::where('uuid', $id)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            'content' => 'required|string|min:1',
        ]);

        $content = $validated['content'];

        // Contextual Anti-Contact Guard
        $hasWork = $conversation->work_id !== null;

        if (!$hasWork) {
            // Pre-agreement: BLOCK contact info
            $this->contactGuard->guardPreAgreement($content);
        } else {
            // Post-agreement: ALLOW contact info, but dispatch audit event if detected
            if ($this->contactGuard->containsContactInfo($content)) {
                event(new ContactInfoDetected($conversation, $user->id, $content));
            }
        }

        $message = MessageModel::create([
            'uuid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'content' => $content,
        ]);

        event(new MessageSent($message));

        return response()->json([
            'data' => [
                'id' => $message->uuid,
                'conversation_id' => $conversation->uuid,
                'sender_id' => $message->sender_id,
                'content' => $message->content,
                'created_at' => $message->created_at?->toISOString(),
            ],
            'message' => 'Mensaje enviado.',
        ], 201);
    }
}
