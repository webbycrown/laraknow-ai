<?php

namespace Webbycrown\LaraknowAi\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantConfig
{
    /**
     * @return array<int, string>
     */
    public function configuredTables(): array
    {
        return $this->stringList('laraknow.allowed_tables');
    }

    /**
     * @return array<int, string>
     */
    public function blockedColumns(): array
    {
        return array_values(array_unique(array_map('strtolower', array_merge(
            $this->stringList('laraknow.blocked_columns'),
            [
                'password',
                'remember_token',
                'token',
                'api_token',
                '_token',
                'secret',
                'key',
                'access_token',
                'refresh_token',
                'payload',
                'exception',
                'email',
                'email_verified_at',
                'phone',
                'phone_number',
                'mobile_number',
                'address',
                'billing_address',
                'client_address',
                'avatar',
                'attachments',
                'birth_date',
                'passport_no',
                'passport_expiry_date',
                'bank_account_no',
                'ifsc_code',
                'pan_no',
                'user_id',
            ]
        ))));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function blockedTableColumns(): array
    {
        try {
            $value = config('laraknow.blocked_table_columns', []);

            if (! is_array($value)) {
                return [];
            }

            $tables = [];

            foreach ($value as $table => $columns) {
                if (! is_string($table) || ! is_array($columns)) {
                    continue;
                }

                $tables[strtolower($table)] = array_values(array_unique(array_filter(array_map(
                    fn ($column) => is_scalar($column) ? strtolower(trim((string) $column)) : '',
                    $columns
                ))));
            }

            return $tables;
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI table-specific config read failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function stringList(string $key): array
    {
        try {
            $value = config($key, []);

            if (! is_array($value)) {
                return [];
            }

            return array_values(array_filter(array_map(
                fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                $value
            )));
        } catch (Throwable $e) {
            Log::warning('LaraKnow AI config read failed', [
                'key' => $key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

}
