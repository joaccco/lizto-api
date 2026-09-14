<?php

namespace App\Http\Controllers\Api\V1\Works;

use App\Domain\Works\Enums\WorkStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Infrastructure\Persistence\Eloquent\WorkQuoteModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkQuoteController extends Controller
{
    public function index(string $workUuid, Request $request): JsonResponse
    {
        $work = WorkModel::where('uuid', $workUuid)->firstOrFail();
        $user = $request->user();

        if (!$this->isAuthorizedParticipant($user, $work)) {
            return response()->json(['message' => 'No tenés permiso para consultar este trabajo.'], 403);
        }

        $quotes = $work->quotes()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $quotes->map(fn($q) => $this->formatQuote($q)),
        ]);
    }

    public function store(string $workUuid, Request $request): JsonResponse
    {
        $work = WorkModel::where('uuid', $workUuid)->firstOrFail();
        $user = $request->user();

        if (!$this->isAssignedProvider($user, $work)) {
            return response()->json(['message' => 'Solo el profesional asignado puede emitir presupuestos.'], 403);
        }

        if (!$work->provider || !$work->provider->isIdentityVerified()) {
            return response()->json(['message' => 'Solo los profesionales con identidad verificada pueden emitir presupuestos.'], 403);
        }

        if ($this->isWorkClosed($work)) {
            return response()->json(['message' => 'No se pueden emitir presupuestos para trabajos finalizados o cancelados.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'currency' => 'nullable|string|max:3',
            'breakdown_items' => 'nullable|array',
            'estimated_hours' => 'nullable|integer|min:1',
            'valid_until' => 'nullable|date',
            'terms_conditions' => 'nullable|string',
        ]);

        $quote = WorkQuoteModel::create([
            'uuid' => (string) Str::uuid(),
            'work_id' => $work->id,
            'provider_id' => $work->provider_id,
            'client_id' => $work->client_id,
            'amount' => $validated['amount'],
            'currency' => $validated['currency'] ?? 'ARS',
            'breakdown_items' => $validated['breakdown_items'] ?? [],
            'estimated_hours' => $validated['estimated_hours'] ?? null,
            'valid_until' => $validated['valid_until'] ?? now()->addDays(7),
            'terms_conditions' => $validated['terms_conditions'] ?? null,
            'origin' => 'manual',
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => $this->formatQuote($quote),
            'message' => 'Presupuesto enviado al cliente.',
        ], 201);
    }

    public function accept(string $workUuid, string $quoteUuid, Request $request): JsonResponse
    {
        $user = $request->user();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($workUuid, $quoteUuid, $user) {
            $work = WorkModel::where('uuid', $workUuid)->lockForUpdate()->first();
            if (!$work) {
                return response()->json(['message' => 'Trabajo no encontrado.'], 404);
            }

            if (!$this->isWorkClient($user, $work)) {
                return response()->json(['message' => 'Solo el cliente del trabajo puede aceptar presupuestos.'], 403);
            }

            if ($this->isWorkClosed($work)) {
                return response()->json(['message' => 'No se pueden procesar presupuestos para trabajos finalizados o cancelados.'], 422);
            }

            $quote = WorkQuoteModel::where('uuid', $quoteUuid)->where('work_id', $work->id)->first();
            if (!$quote) {
                return response()->json(['message' => 'Presupuesto no encontrado.'], 404);
            }

            if ($quote->status !== 'pending') {
                return response()->json(['message' => 'Solo se pueden aceptar o rechazar presupuestos en estado pendiente.'], 422);
            }

            if ($quote->valid_until !== null && $quote->valid_until->isPast()) {
                return response()->json(['message' => 'El presupuesto ha vencido y no puede ser aceptado.'], 422);
            }

            $hasAcceptedQuote = WorkQuoteModel::where('work_id', $work->id)
                ->where('status', 'accepted')
                ->exists();

            if ($hasAcceptedQuote) {
                return response()->json(['message' => 'El trabajo ya posee un presupuesto aceptado.'], 422);
            }

            $quote->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            $work->applyAcceptedQuote($quote);

            return response()->json([
                'data' => $this->formatQuote($quote),
                'message' => 'Presupuesto aceptado correctamente.',
            ]);
        });
    }

    public function reject(string $workUuid, string $quoteUuid, Request $request): JsonResponse
    {
        $work = WorkModel::where('uuid', $workUuid)->firstOrFail();
        $user = $request->user();

        if (!$this->isWorkClient($user, $work)) {
            return response()->json(['message' => 'Solo el cliente del trabajo puede rechazar presupuestos.'], 403);
        }

        if ($this->isWorkClosed($work)) {
            return response()->json(['message' => 'No se pueden procesar presupuestos para trabajos finalizados o cancelados.'], 422);
        }

        $quote = WorkQuoteModel::where('uuid', $quoteUuid)->where('work_id', $work->id)->firstOrFail();

        if ($quote->status !== 'pending') {
            return response()->json(['message' => 'Solo se pueden aceptar o rechazar presupuestos en estado pendiente.'], 422);
        }

        $quote->update([
            'status' => 'rejected',
        ]);

        return response()->json([
            'data' => $this->formatQuote($quote),
            'message' => 'Presupuesto rechazado.',
        ]);
    }

    private function isWorkClient($user, WorkModel $work): bool
    {
        return (int) $user->id === (int) $work->client_id;
    }

    private function isAssignedProvider($user, WorkModel $work): bool
    {
        $providerUserId = $work->provider?->user_id;
        return $providerUserId !== null && (int) $user->id === (int) $providerUserId;
    }

    private function isAuthorizedParticipant($user, WorkModel $work): bool
    {
        return $this->isWorkClient($user, $work) || $this->isAssignedProvider($user, $work);
    }

    private function isWorkClosed(WorkModel $work): bool
    {
        $statusValue = $work->status instanceof WorkStatus ? $work->status->value : (string) $work->status;
        return in_array($statusValue, [WorkStatus::Completed->value, WorkStatus::Cancelled->value], true);
    }

    private function formatQuote(WorkQuoteModel $quote): array
    {
        return [
            'id' => $quote->uuid,
            'uuid' => $quote->uuid,
            'amount' => (float) $quote->amount,
            'currency' => $quote->currency,
            'breakdown_items' => $quote->breakdown_items ?? [],
            'estimated_hours' => $quote->estimated_hours,
            'valid_until' => $quote->valid_until?->toISOString(),
            'terms_conditions' => $quote->terms_conditions,
            'origin' => $quote->origin ?? 'manual',
            'status' => $quote->status,
            'accepted_at' => $quote->accepted_at?->toISOString(),
            'created_at' => $quote->created_at?->toISOString(),
        ];
    }
}
