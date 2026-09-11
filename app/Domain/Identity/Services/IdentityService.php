<?php

namespace App\Domain\Identity\Services;

use App\Domain\Professional\Services\ProfessionalRequirementsEvaluator;
use App\Infrastructure\KYC\IdentityProviderContract;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\ProfessionalMVU;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IdentityService
{
    public function __construct(
        protected IdentityProviderContract $identityProvider,
        protected ?ProfessionalRequirementsEvaluator $evaluator = null
    ) {
        $this->evaluator = $this->evaluator ?? app(ProfessionalRequirementsEvaluator::class);
    }

    /**
     * Initiate KYC verification session with Didit.
     */
    public function initiateKYC(UserModel $user, array $options = []): array
    {
        $existing = Identity::where('user_id', $user->id)->first();
        if ($existing && $existing->status === 'approved') {
            return [
                'status' => 'already_approved',
                'identity_id' => $existing->id,
                'message' => 'Identidad ya verificada previamente.',
            ];
        }

        $workflowId = config('services.didit.workflow_id');
        if (empty($workflowId)) {
            throw new \RuntimeException('DIDIT_WORKFLOW_ID is not configured. Identity verification requires a valid workflow ID.');
        }
        $vendorData = (string) ($user->uuid ?? $user->id);

        $params = [
            'workflow_id' => $workflowId,
            'vendor_data' => $vendorData,
            'contact_details' => [
                'email' => $user->email,
            ],
        ];

        if (!empty($options['callback']) || !empty($options['redirect_url'])) {
            $params['callback'] = $options['callback'] ?? $options['redirect_url'];
        }

        if (!empty($options['metadata'])) {
            $params['metadata'] = is_array($options['metadata']) ? json_encode($options['metadata']) : (string) $options['metadata'];
        }

        $sessionData = $this->identityProvider->createSession($params);

        $sessionId = $sessionData['session_id'] ?? $sessionData['id'] ?? null;
        $url = $sessionData['url'] ?? $sessionData['session_url'] ?? null;

        $identity = Identity::updateOrCreate(
            ['user_id' => $user->id],
            [
                'status' => 'pending',
                'didit_kyc_response_id' => $sessionId,
                'rejection_reason' => null,
            ]
        );

        $consentVersion = $options['consent_version'] ?? config('privacy.consent_version', '1.0');
        $consentTimestamp = now();

        AuditLog::create([
            'event' => 'identity_consent_recorded',
            'subject_type' => 'Identity',
            'subject_id' => $identity->id,
            'user_id' => $user->id,
            'action' => 'consent_granted',
            'new_values' => [
                'consent_version' => $consentVersion,
                'consent_timestamp' => $consentTimestamp->toISOString(),
                'legal_framework' => 'Ley 25.326',
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => $consentTimestamp,
        ]);

        AuditLog::create([
            'event' => 'identity_session_initiated',
            'subject_type' => 'Identity',
            'subject_id' => $identity->id,
            'user_id' => $user->id,
            'action' => 'initiate',
            'new_values' => [
                'session_id' => $sessionId,
                'consent_version' => $consentVersion,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);

        return [
            'status' => 'pending',
            'session_id' => $sessionId,
            'url' => $url,
            'identity_id' => $identity->id,
        ];
    }

    /**
     * Apply verification decision from Didit asynchronously and idempotently.
     */
    public function applyDecision(string $sessionId): array
    {
        /** @var Identity|null $identity */
        $identity = Identity::where('didit_kyc_response_id', $sessionId)->first();

        // Idempotency: if already approved, acknowledge and skip duplicate state changes
        if ($identity && $identity->status === 'approved') {
            return [
                'status' => 'already_processed',
                'session_id' => $sessionId,
                'identity_id' => $identity->id,
            ];
        }

        // Consult decision directly from Didit API (trusted source of truth)
        $decision = $this->identityProvider->getDecision($sessionId);

        $statusVal = strtolower($decision['status'] ?? $decision['decision'] ?? '');
        $isApproved = in_array($statusVal, ['approved', 'pass', 'success', 'verified'], true)
            || (isset($decision['decision']) && strtoupper($decision['decision']) === 'PASS');

        // If identity wasn't found by session_id, attempt to correlate via vendor_data
        if (!$identity && !empty($decision['vendor_data'])) {
            $user = UserModel::where('uuid', $decision['vendor_data'])->first();
            if ($user) {
                $identity = Identity::firstOrNew(['user_id' => $user->id]);
                $identity->didit_kyc_response_id = $sessionId;
            }
        }

        if (!$identity) {
            Log::warning('Didit decision received for unknown session_id', ['session_id' => $sessionId]);
            return [
                'status' => 'not_found',
                'session_id' => $sessionId,
            ];
        }

        if ($isApproved) {
            return $this->processApprovedDecision($identity, $sessionId, $decision);
        }

        return $this->processRejectedDecision($identity, $sessionId, $decision);
    }

    protected function processApprovedDecision(Identity $identity, string $sessionId, array $decision): array
    {
        return DB::transaction(function () use ($identity, $sessionId, $decision) {
            $docData = $decision['document'] ?? $decision['extracted_data'] ?? [];
            $docNumber = $docData['document_number'] ?? $decision['document_number'] ?? $decision['dni'] ?? null;
            $firstName = $docData['first_name'] ?? $decision['first_name'] ?? $decision['firstname'] ?? null;
            $lastName = $docData['last_name'] ?? $decision['last_name'] ?? $decision['lastname'] ?? null;
            $birthDate = $docData['birth_date'] ?? $decision['birth_date'] ?? $decision['birthdate'] ?? null;

            // DNI duplicate detection against other approved users
            if (!empty($docNumber)) {
                $duplicate = Identity::where('status', 'approved')
                    ->where('user_id', '!=', $identity->user_id)
                    ->get()
                    ->first(fn ($idRecord) => $idRecord->dni === $docNumber);

                if ($duplicate) {
                    $reason = 'El documento DNI devuelto por Didit ya se encuentra verificado por otro usuario.';
                    $this->handleRejection($identity, $reason, ['dni' => 'duplicate_detected']);

                    return [
                        'status' => 'rejected',
                        'session_id' => $sessionId,
                        'reason' => $reason,
                        'errors' => ['dni' => 'duplicate_detected'],
                    ];
                }
            }

            $oldValues = $identity->getOriginal();

            $identity->dni = $docNumber ?? $identity->dni;
            $identity->firstname = $firstName ?? $identity->firstname;
            $identity->lastname = $lastName ?? $identity->lastname;
            $identity->birthdate = $birthDate ?? $identity->birthdate;
            $identity->status = 'approved';
            $identity->verified_at = now();
            $identity->verified_by = 'didit_id';
            $identity->rejection_reason = null;
            $identity->save();

            AuditLog::create([
                'event' => 'identity_verified',
                'subject_type' => 'Identity',
                'subject_id' => $identity->id,
                'user_id' => $identity->user_id,
                'action' => 'approve',
                'old_values' => $oldValues,
                'new_values' => $identity->toArray(),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            // If user has a provider profile, link identity and recalculate MVU via evaluator
            $mvuStatus = null;
            $user = $identity->user;
            if ($user && $user->providerProfile) {
                $providerProfile = $user->providerProfile;
                $providerProfile->load('categories.category');

                $mvu = ProfessionalMVU::firstOrNew(['provider_id' => $providerProfile->id]);
                $mvu->identity_id = $identity->id;
                $mvu->identity_verified_at = now();
                $mvu->save();

                $providerProfile->setRelation('mvu', $mvu);
                $providerProfile->load('categories.category');

                // Evaluator determines overall status (matrícula, antecedentes, etc.)
                $evalResult = $this->evaluator->evaluate($providerProfile);
                $mvu->overall_verification_status = $evalResult->isEligible ? 'approved' : 'pending';
                $mvu->save();

                $mvuStatus = $mvu->overall_verification_status;
                // Zero writes to legacy providerProfile flags (is_verified, status)
            }

            return [
                'status' => 'approved',
                'session_id' => $sessionId,
                'identity_id' => $identity->id,
                'mvu_status' => $mvuStatus,
            ];
        });
    }

    protected function processRejectedDecision(Identity $identity, string $sessionId, array $decision): array
    {
        $reason = $decision['rejection_reason'] ?? $decision['reason'] ?? 'Verificación biométrica o documental no aprobada por Didit.';
        $this->handleRejection($identity, $reason, $decision);

        if ($identity->user && $identity->user->providerProfile) {
            $mvu = ProfessionalMVU::firstOrNew(['provider_id' => $identity->user->providerProfile->id]);
            $mvu->overall_verification_status = 'rejected';
            $mvu->save();
        }

        return [
            'status' => 'rejected',
            'session_id' => $sessionId,
            'reason' => $reason,
        ];
    }

    /**
     * Mark identity as approved (internal/admin).
     */
    public function handleApproval(Identity $identity, array $diditData = []): void
    {
        $this->processApprovedDecision($identity, $identity->didit_kyc_response_id ?? 'direct', $diditData);
    }

    /**
     * Mark identity as rejected.
     */
    public function handleRejection(Identity $identity, string $reason, array $errors = []): void
    {
        $oldValues = $identity->getOriginal();

        $identity->status = 'rejected';
        $identity->rejection_reason = $reason;
        $identity->save();

        AuditLog::create([
            'event' => 'identity_rejected',
            'subject_type' => 'Identity',
            'subject_id' => $identity->id,
            'user_id' => $identity->user_id,
            'action' => 'reject',
            'old_values' => $oldValues,
            'new_values' => array_merge($identity->toArray(), ['errors' => $errors]),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
