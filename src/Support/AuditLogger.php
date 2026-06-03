<?php

namespace Webbycrown\LaraknowAi\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuditLogger
{
    /**
     * Write an audit event to configured sinks.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = [], string $level = 'info'): void
    {
        try {
            $context = $this->sanitizeContext($context);
            $payload = [
                'event' => $event,
                'level' => $level,
                'context' => $context,
            ];

            Log::{$level}('LaraKnow AI audit event', $payload);

            if ($this->usesDriver('database')) {
                $this->recordToDatabase($event, $context, $level);
            }
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI audit logging failed', [
                'event' => $event,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;

            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = $this->sanitizeScalar($key, $value);
                continue;
            }

            $sanitized[$key] = '[unserializable]';
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordToDatabase(string $event, array $context, string $level): void
    {
        $table = 'laraknow_audit_logs';

        if ($table === '' || ! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->insert([
            'event' => $event,
            'level' => $level,
            'user_id' => $context['user_id'] ?? null,
            'conversation_id' => $context['conversation_id'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'context' => json_encode($context, JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function usesDriver(string $driver): bool
    {
        if ($driver !== 'file') {
            return false;
        }

        return true;
    }

    private function sanitizeScalar(string $key, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = $this->redactSecrets($value);
        $maxLength = 1000;

        return mb_strlen($value) > $maxLength
            ? mb_substr($value, 0, $maxLength - 3).'...'
            : $value;
    }

    private function redactSecrets(string $value): string
    {
        $patterns = [
            '/(api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret|authorization)\s*[:=]\s*[^\s,;]+/iu',
            '/bearer\s+[a-z0-9._~+\/=-]+/iu',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1=[redacted]', $value) ?? $value;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/(password|token|secret|api[_-]?key|authorization|credential|cookie|session)/iu', $key);
    }
}
