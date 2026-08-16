<?php

namespace App\Domain\Offers\Services;

use App\Domain\Offers\Exceptions\ContactInfoDetectedException;

class ContactInfoGuard
{
    public function containsContactInfo(string $text): bool
    {
        $normalized = mb_strtolower($text);

        // 1. Keyword check
        $keywords = ['whatsapp', 'wsp', 'wa.me', 'gmail', 'hotmail', 'outlook', 'email', 'e-mail', 'telegram', 'ig:', 'instagram'];
        foreach ($keywords as $kw) {
            if (str_contains($normalized, $kw)) {
                return true;
            }
        }

        // 2. Email pattern check
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text)) {
            return true;
        }

        // 3. Phone numbers (contiguous digit sequences of 7+ digits or formatted AR phone patterns)
        if (preg_match('/[0-9]{7,}/', preg_replace('/[\s.-]/', '', $text))) {
            return true;
        }

        if (preg_match('/(\+?54)?\s*(9)?\s*(11|15|[0-9]{3,4})[\s.-]*[0-9]{3,4}[\s.-]*[0-9]{3,4}/i', $text)) {
            return true;
        }

        return false;
    }

    public function guardPreAgreement(string $text): void
    {
        if ($this->containsContactInfo($text)) {
            throw new ContactInfoDetectedException();
        }
    }
}
