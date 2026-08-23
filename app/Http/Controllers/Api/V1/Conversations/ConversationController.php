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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function __construct(protected ContactInfoGuard $contactGuard) {}

    public function messages(string $id, Request $request): JsonResponse
    {
        $conversation = ConversationModel::where('uuid', $id)
            ->with(['client', 'provider.user', 'serviceRequest.category', 'work'])
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        Gate::authorize('view', $conversation);

        $user = $request->user();
        $messages = $conversation->messages()->with('sender')->get();

        $providerUser = $conversation->provider?->user;
        $providerProfile = $conversation->provider;

        $workStatus = $conversation->work?->status?->value ?? $conversation->serviceRequest?->status?->value ?? 'confirmed';
        $isClosed = in_array($workStatus, ['completed', 'cancelled'], true);

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->uuid,
                'work_id' => $conversation->work?->uuid,
                'client_id' => $conversation->client_id,
                'client_name' => $conversation->client?->name ?? 'Cliente',
                'provider_id' => $conversation->provider_id,
                'provider_name' => $providerUser?->name ?? 'Profesional',
                'provider_avatar' => $providerUser?->avatar_url,
                'provider_rating' => (float) ($providerProfile?->avg_rating ?? 5.0),
                'provider_reviews' => (int) ($providerProfile?->total_reviews ?? 0),
                'category_name' => $conversation->serviceRequest?->category?->name ?? 'Servicio general',
                'raw_prompt' => $conversation->serviceRequest?->raw_prompt ?? '',
                'work_status' => $workStatus,
                'is_closed' => $isClosed,
                'messages' => $messages->map(fn($m) => [
                    'id' => $m->uuid,
                    'sender_id' => $m->sender_id,
                    'sender_name' => $m->sender?->name ?? 'Usuario',
                    'content' => $m->content,
                    'created_at' => $m->created_at?->toISOString(),
                    'is_me' => $user ? ($m->sender_id === $user->id) : false,
                ]),
            ],
        ]);
    }

    public function sendMessage(Request $request, string $id): JsonResponse
    {
        $conversation = ConversationModel::where('uuid', $id)
            ->with(['work', 'serviceRequest'])
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversación no encontrada.'], 404);
        }

        Gate::authorize('sendMessage', $conversation);

        $workStatus = $conversation->work?->status?->value ?? $conversation->serviceRequest?->status?->value ?? 'confirmed';
        if (in_array($workStatus, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'El chat ha sido cerrado porque el trabajo fue completado o cancelado.',
            ], 409);
        }

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
                'is_me' => true,
            ],
            'message' => 'Mensaje enviado.',
        ], 201);
    }
}
