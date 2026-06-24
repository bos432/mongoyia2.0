<?php

namespace console\components;

class AcceptedEvidenceGuard
{
    public const VERSION = 'MONGOYIA_ACCEPTED_EVIDENCE_SECRET_GUARD_V1';

    public static function sensitiveReason(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $patterns = [
            'private key material' => '/(-----BEGIN [A-Z ]*PRIVATE KEY-----|BEGIN RSA|BEGIN EC|BEGIN OPENSSH)/i',
            'authorization header' => '/\b(Basic|Bearer)\s+[A-Za-z0-9._+\/=-]{8,}/i',
            'secret assignment' => '/\b(client[_-]?secret|api[_-]?key|hmac[_-]?secret|webhook[_-]?secret|smtp[_-]?password|password|token|secret)\s*[:=]\s*\S+/i',
            'credentialed URL' => '/https?:\/\/[^\/\s:@]+:[^\/\s@]+@/i',
            'secret query parameter' => '/[?&](client[_-]?secret|api[_-]?key|hmac[_-]?secret|webhook[_-]?secret|password|token|secret)=/i',
        ];

        foreach ($patterns as $reason => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $reason;
            }
        }

        return '';
    }
}
