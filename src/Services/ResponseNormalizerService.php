<?php

namespace Webbycrown\LaraknowAi\Services;

use Webbycrown\LaraknowAi\Support\ReportReplyBuilder;
use Illuminate\Support\Str;

/**
 * ResponseNormalizerService
 *
 * Transforms raw AI data arrays into the canonical shape expected by the
 * chat front-end. All methods are stateless and contain no project-specific
 * logic; they work with any Laravel host application.
 */
class ResponseNormalizerService
{
    /**
     * Normalize AI data into the shape expected by the chat UI.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $prompt
     * @return array<string, mixed>
     */
    public function normalizeAiData(array $data, ?string $prompt = null): array
    {
        $nestedData = $this->decodeJsonPayload((string) ($data['reply'] ?? ''));

        if ($nestedData) {
            return $this->normalizeAiData($nestedData, $prompt);
        }

        $normalizedData = $this->normalizeData($data['data'] ?? []);
        $reply          = $this->cleanReply((string) ($data['reply'] ?? ''), $normalizedData);
        $reportBuilder  = new ReportReplyBuilder;
        $reportSummary  = $reportBuilder->build($normalizedData);

        if ($this->genericReplyHasUnhelpfulData($reply, $normalizedData, $prompt)) {
            return [
                'reply'      => $this->noMatchingRecordsMessage(),
                'data'       => [],
                'suggestion' => 'Please try again with a more specific question.',
            ];
        }

        if ($this->relatedEntityDataLooksUnverified($normalizedData, $prompt)) {
            return [
                'reply'      => $this->noMatchingRecordsMessage(),
                'data'       => [],
                'suggestion' => 'Please try again with a more specific question.',
            ];
        }

        if (
            $this->shouldRenderReportFallback($prompt)
            && $reportSummary !== ''
            && $reportBuilder->replyNeedsReportData($reply, $normalizedData)
        ) {
            $reply = $reportSummary;
        } elseif ($this->shouldAppendDataPreview($prompt)) {
            $reply = $this->appendDataPreviewToReply($reply, $normalizedData);
        }

        return [
            'reply'      => $reply,
            'data'       => $normalizedData,
            'suggestion' => isset($data['suggestion']) ? $this->cleanText((string) $data['suggestion']) : null,
        ];
    }

    /**
     * Attach the active conversation ID to a normalized response payload.
     *
     * @param  array<string, mixed>  $data
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    public function withConversationId(array $data, $response): array
    {
        return [
            ...$data,
            'conversation_id' => $response->conversationId,
        ];
    }

    /**
     * Replace provider-specific rate-limit text that may arrive as normal AI content.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */    private function noMatchingRecordsMessage(): string
    {
        return (string) config(
            'laraknow.ui.messages.no_matching_records',
            'We were unable to locate any records matching your criteria. You may want to adjust your search terms.'
        );
    }
    public function sanitizeRateLimitPayload(array $payload): array
    {
        foreach (['reply', 'message', 'suggestion'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                continue;
            }

            if ($this->containsProviderRateLimitText($payload[$key])) {
                $payload[$key] = $this->rateLimitMessage();
            }
        }

        return $payload;
    }

    // -------------------------------------------------------------------------
    // Internal normalisation helpers (no project-specific logic)
    // -------------------------------------------------------------------------

    private function containsProviderRateLimitText(string $value): bool
    {
        $value = strtolower($value);

        return str_contains($value, 'rate limited')
            || str_contains($value, 'rate limit')
            || str_contains($value, 'ai provider')
            || str_contains($value, '[groq]');
    }

    private function rateLimitMessage(): string
    {
        return (string) config(
            'laraknow.ui.messages.rate_limited',
            'Something went wrong. Please try again in a moment or contact admin.'
        );
    }

    private function cleanReply(string $reply, array $data): string
    {
        $reply = $this->cleanText($reply);

        if (empty($data)) {
            return $reply;
        }

        $reply = $this->stripRecordBlocks($reply);

        return $reply !== '' ? $reply : '';
    }

    private function appendDataPreviewToReply(string $reply, array $data): string
    {
        $rows = $this->previewRows($data);

        if (
            empty($rows)
            || $this->singleMetricAlreadyAnswered($reply, $rows)
        ) {
            return $reply;
        }

        // If the assistant already included structured rows in its reply,
        // check whether it included all rows. If the reply contains fewer
        // structured rows than the actual dataset, continue and append the
        // full preview so users can see the complete result set.
        if ($this->replyAlreadyContainsRowData($reply, $rows)) {
            $structuredCount = $this->countStructuredRowsInReply($reply);

            if ($structuredCount >= count($rows)) {
                return $reply;
            }
            // otherwise the model reply appears truncated; fall through to append preview
        }

        // Prefer a compact markdown table when rows contain consistent columns
        $columns = $this->pickPreviewColumns($rows);

        if (! empty($columns) && count($columns) > 1 && count($columns) <= $this->maxPreviewColumns()) {
            $preview = $this->readablePreviewTable($rows, $columns);
        } else {
            $preview = $this->readablePreviewList($rows);
        }

        if ($preview === '') {
            return $reply;
        }

        return trim($reply) . "\n\n" . $preview;
    }

    private function shouldAppendDataPreview(?string $prompt): bool
    {
        if ((bool) config('laraknow.ui.responses.auto_append_data_preview', false)) {
            return true;
        }

        if (! (bool) config('laraknow.ui.responses.append_data_preview_when_requested', true)) {
            return false;
        }

        $prompt = trim((string) $prompt);

        if ($prompt === '') {
            return false;
        }

        if (preg_match('/\b(how many|count|total|number of|sum|average|avg|min|max)\b.*\b(each|every|per|by|grouped by|group by)\b/i', $prompt)) {
            return true;
        }

        if ($this->looksLikeRankedValueRequest($prompt)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(table|tabular|row|rows|record|records|list|show|display|details|breakdown|export|find|search|browse|available)\b/i',
            $prompt
        ) || (bool) preg_match('/\b[a-z][a-z0-9 _-]*s?\s+(from|for|by)\s+["\']?[\pL\pN][\pL\pN .&_-]*/iu', $prompt);
    }

    private function shouldRenderReportFallback(?string $prompt): bool
    {
        if ((bool) config('laraknow.ui.responses.auto_render_report_fallback', false)) {
            return true;
        }

        if (! (bool) config('laraknow.ui.responses.render_report_fallback_when_requested', true)) {
            return false;
        }

        $prompt = trim((string) $prompt);

        if ($prompt === '') {
            return false;
        }

        if (preg_match('/\b(without|no|dont|don\'t)\b.*\b(raw|records?|rows?|table|details?|breakdown)\b/i', $prompt)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(report|dashboard|summary|summarize|overview|breakdown|analytics|metrics|stats|statistics)\b/i',
            $prompt
        );
    }

    private function genericReplyHasUnhelpfulData(string $reply, array $data, ?string $prompt): bool
    {
        if (! $this->isGenericRecordReply($reply)) {
            return false;
        }

        $rows = $this->previewRows($data);

        if (empty($rows)) {
            return false;
        }

        if ($this->looksLikeRankedValueRequest($prompt)) {
            foreach ($rows as $row) {
                if ($this->hasRankingValueColumn($row)) {
                    return false;
                }
            }

            return true;
        }

        if (! $this->looksLikeRecordRequest($prompt) || $this->looksLikeMetricRequest($prompt)) {
            return false;
        }

        foreach ($rows as $row) {
            if ($this->hasReadableRecordColumn($row)) {
                return false;
            }
        }

        return true;
    }

    private function relatedEntityDataLooksUnverified(array $data, ?string $prompt): bool
    {
        $entity = $this->relatedEntityValue($prompt);

        if ($entity === null) {
            return false;
        }

        $rows = $this->previewRows($data);

        if (empty($rows)) {
            return false;
        }

        if ($this->rowsContainValue($rows, $entity)) {
            return false;
        }

        foreach ($rows as $row) {
            foreach (array_keys($this->scalarPreviewColumns($row)) as $column) {
                if ($this->isRawIdentifierColumn((string) $column)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function relatedEntityValue(?string $prompt): ?string
    {
        $prompt = trim((string) $prompt);

        if ($prompt === '' || ! $this->looksLikeRecordRequest($prompt) || $this->looksLikeMetricRequest($prompt)) {
            return null;
        }

        if (! preg_match('/\b(?:from|for|by)\s+["\']?([\pL\pN][\pL\pN .&_-]{1,80})/iu', $prompt, $matches)) {
            return null;
        }

        $entity             = trim((string) $matches[1]);
        $genericEntityWords = implode('|', array_map(
            fn(string $word): string => preg_quote($word, '/'),
            $this->genericEntityWords()
        ));
        $entity = preg_replace('/\s+(?:' . $genericEntityWords . ')\b.*$/iu', '', $entity) ?? $entity;
        $entity = trim($entity, " \t\n\r\0\x0B\"'.,?!:;()[]{}");

        return mb_strlen($entity) >= 2 ? $entity : null;
    }

    /** @return array<int, string> */
    private function genericEntityWords(): array
    {
        $configured = config('laraknow.response_detection.generic_entity_words', []);

        if (! is_array($configured)) {
            $configured = [];
        }

        $words = array_merge([
            'record',
            'records',
            'item',
            'items',
            'entry',
            'entries',
            'data',
            'result',
            'results',
            'entity',
            'entities',
            'row',
            'rows',
            'details',
        ], array_filter(array_map(
            fn($word): string => is_scalar($word) ? trim((string) $word) : '',
            $configured
        )));

        usort($words, fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        return array_values(array_unique(array_filter($words)));
    }

    private function rowsContainValue(array $rows, string $needle): bool
    {
        $needle = mb_strtolower($needle);

        foreach ($rows as $row) {
            foreach ($this->scalarPreviewColumns($row) as $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                if (str_contains(mb_strtolower((string) $value), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isGenericRecordReply(string $reply): bool
    {
        return (bool) preg_match(
            '/^\s*(here (are|is) (the )?(records|data|results)( i found)?\\.?|i found (some )?(records|data|results)\\.?)\\s*$/iu',
            $reply
        );
    }

    private function looksLikeRecordRequest(?string $prompt): bool
    {
        $prompt = trim((string) $prompt);

        if ($prompt === '') {
            return false;
        }

        if (preg_match('/\b(list|show|display|find|search|browse|available|records?|items?|entries?)\b/iu', $prompt)) {
            return true;
        }

        return (bool) preg_match('/\b[\pL\pN][\pL\pN _-]*s?\s+(from|for|by)\s+["\']?[\pL\pN][\pL\pN .&_-]*/iu', $prompt);
    }

    private function looksLikeMetricRequest(?string $prompt): bool
    {
        return (bool) preg_match(
            '/\b(how many|count|total|sum|average|avg|min|max|metric|metrics|stat|stats|statistics|number of)\b/iu',
            (string) $prompt
        );
    }

    private function looksLikeRankedValueRequest(?string $prompt): bool
    {
        $terms = $this->configuredWordTerms('laraknow.response_detection.ranked_request_terms');

        if (empty($terms)) {
            return false;
        }

        return (bool) preg_match('/\b(' . implode('|', array_map(
            fn(string $term): string => preg_quote($term, '/'),
            $terms
        )) . ')()\b/iu', (string) $prompt);
    }

    private function hasRankingValueColumn(array $row): bool
    {
        $terms = $this->configuredWordTerms('laraknow.response_detection.ranking_value_columns');

        if (empty($terms)) {
            return false;
        }

        foreach (array_keys($this->scalarPreviewColumns($row)) as $column) {
            $column = strtolower((string) $column);

            if (preg_match('/(' . implode('|', array_map(
                fn(string $term): string => preg_quote($term, '/'),
                $terms
            )) . ')$/i', $column)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function configuredWordTerms(string $configKey): array
    {
        $configured = config($configKey, $this->defaultWordTerms($configKey));

        if (! is_array($configured)) {
            return [];
        }

        $terms = array_values(array_unique(array_filter(array_map(
            fn($term): string => is_scalar($term) ? trim((string) $term) : '',
            $configured
        ))));

        usort($terms, fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        return $terms;
    }

    /** @return array<int, string> */
    private function defaultWordTerms(string $configKey): array
    {
        return match ($configKey) {
            'laraknow.response_detection.ranked_request_terms' => [
                'cheapest',
                'lowest',
                'least',
                'minimum',
                'min',
                'highest',
                'maximum',
                'max',
                'most expensive',
                'expensive',
                'costliest',
                'newest',
                'latest',
                'oldest',
                'earliest',
                'best',
                'worst',
                'top',
                'bottom',
            ],
            'laraknow.response_detection.ranking_value_columns' => [
                'price',
                'amount',
                'cost',
                'rate',
                'value',
                'total',
                'sum',
                'avg',
                'average',
                'min',
                'max',
                'count',
                'date',
                'time',
                'score',
                'rank',
                'rating',
                'quantity',
                'qty',
            ],
            default => [],
        };
    }

    private function hasReadableRecordColumn(array $row): bool
    {
        foreach (array_keys($this->scalarPreviewColumns($row)) as $column) {
            $column = strtolower((string) $column);

            if ($this->isRawIdentifierColumn($column)) {
                continue;
            }

            if (preg_match('/(count|total|sum|avg|average|min|max|metric|stat|number|rate)$/', $column)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function isRawIdentifierColumn(string $column): bool
    {
        return (bool) preg_match('/(^id$|_id$|^.*able_id$)/', strtolower($column));
    }

    private function replyAlreadyContainsRowData(string $reply, array $rows): bool
    {
        $reply         = mb_strtolower($reply);
        $matchedRows   = 0;
        $requiredValues = $this->replyHasStructuredRows($reply) ? 2 : 1;

        foreach (array_slice($rows, 0, 3) as $row) {
            $matchedValues = 0;

            foreach ($this->scalarPreviewColumns($row) as $value) {
                $value = $this->cleanText((string) $value);

                if (mb_strlen($value) < 3) {
                    continue;
                }

                if (str_contains($reply, mb_strtolower($value))) {
                    $matchedValues++;
                }
            }

            if ($matchedValues >= $requiredValues) {
                $matchedRows++;
            }
        }

        return $matchedRows >= min($this->replyHasStructuredRows($reply) ? 2 : 3, count($rows));
    }

    private function singleMetricAlreadyAnswered(string $reply, array $rows): bool
    {
        if (count($rows) !== 1) {
            return false;
        }

        $columns = $this->scalarPreviewColumns($rows[0]);

        if (count($columns) !== 1) {
            return false;
        }

        $column = (string) array_key_first($columns);
        $value  = $this->displayPreviewValue(reset($columns));
        $reply  = mb_strtolower($this->cleanText($reply));

        return str_contains($reply, mb_strtolower($this->humanColumnLabel($column)))
            || ($value !== '' && str_contains($reply, mb_strtolower($value)));
    }

    private function replyHasStructuredRows(string $reply): bool
    {
        return (bool) preg_match('/^\s*(\|.*\||[-*]\s+|\d+[.)]\s+)/m', $reply);
    }

    private function readablePreviewList(array $rows): string
    {
        $columns = $this->pickPreviewColumns($rows);

        if (empty($columns)) {
            return '';
        }

        $lines = [];

        foreach (array_slice($rows, 0, $this->maxPreviewRows()) as $row) {
            $parts = [];

            foreach ($columns as $column) {
                $parts[] = $this->humanColumnLabel($column) . ': ' . $this->displayPreviewValue($row[$column] ?? null);
            }

            $lines[] = '- ' . implode(', ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * Render a markdown table for preview rows when appropriate.
     *
     * @param array<int, array<string,mixed>> $rows
     * @param array<int,string> $columns
     */
    private function readablePreviewTable(array $rows, array $columns): string
    {
        if (empty($columns)) {
            return '';
        }

        $header = '| ' . implode(' | ', array_map(fn($c) => $this->humanColumnLabel($c), $columns)) . ' |';
        $sep = '| ' . implode(' | ', array_map(fn($_) => '---', $columns)) . ' |';

        $lines = [$header, $sep];

        foreach (array_slice($rows, 0, $this->maxPreviewRows()) as $row) {
            $cells = [];

            foreach ($columns as $column) {
                $cells[] = $this->displayPreviewValue($row[$column] ?? null);
            }

            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines);
    }

    private function pickPreviewColumns(array $rows): array
    {
        $columns = [];
        $preferred = array_map('strtolower', $this->previewColumnOrder());

        foreach ($rows as $row) {
            foreach (array_keys($this->displayablePreviewColumns($row)) as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        if (empty($columns)) {
            return [];
        }

        usort($columns, function (string $left, string $right) use ($preferred): int {
            $leftIndex = array_search(strtolower($left), $preferred, true);
            $rightIndex = array_search(strtolower($right), $preferred, true);

            $leftIndex = $leftIndex === false ? count($preferred) : $leftIndex;
            $rightIndex = $rightIndex === false ? count($preferred) : $rightIndex;

            if ($leftIndex !== $rightIndex) {
                return $leftIndex <=> $rightIndex;
            }

            return strtolower($left) <=> strtolower($right);
        });

        return array_slice($columns, 0, $this->maxPreviewColumns());
    }

    private function maxPreviewRows(): int
    {
        return max(1, (int) config('laraknow.ui.responses.max_preview_rows', 5));
    }

    private function maxPreviewColumns(): int
    {
        return max(1, (int) config('laraknow.ui.responses.max_preview_columns', 4));
    }

    private function previewColumnOrder(): array
    {
        $configured = config('laraknow.ui.responses.preview_column_order', []);

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_values(array_filter(array_map(
            fn($column): string => is_scalar($column) ? trim((string) $column) : '',
            $configured
        )));
    }

    private function previewRows(array $data): array
    {
        $rows = array_is_list($data) ? $data : ($data['data'] ?? []);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            fn($row) => is_array($row) && ! empty($this->scalarPreviewColumns($row))
        ));
    }

    private function scalarPreviewColumns(array $row): array
    {
        return array_filter(
            $row,
            fn($value) => is_null($value) || is_scalar($value)
        );
    }

    private function displayablePreviewColumns(array $row): array
    {
        return array_filter(
            $this->scalarPreviewColumns($row),
            fn($value, $column) => ! $this->isRawIdentifierColumn((string) $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function humanColumnLabel(string $column): string
    {
        return mb_convert_case(str_replace(['_', '-'], ' ', $column), MB_CASE_TITLE, 'UTF-8');
    }

    private function displayPreviewValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not available';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return Str::limit($this->cleanText((string) $value), 120);
    }

    private function stripRecordBlocks(string $text): string
    {
        $text = preg_replace('/```.*?```/su', '', $text) ?? $text;

        // Remove raw tab-separated tables from AI replies.
        $text = preg_replace('/(?:^[^\r\n\t]+\t[^\r\n]+\r?\n){2,}(?:[^\r\n\t]+\t[^\r\n]+)(?:\r?\n)?/m', '', $text) ?? $text;

        $lines = array_filter(
            explode("\n", $text),
            fn($line) => ! preg_match('/^\s*\|.*\|\s*$/', $line)
                && ! preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/', $line)
        );

        return $this->cleanText(implode("\n", $lines));
    }

    /**
     * Count structured rows in an assistant reply (numbered items, bullet rows, or table rows).
     */
    private function countStructuredRowsInReply(string $reply): int
    {
        $count = 0;

        // Numbered items like "1." or "1)"
        $count += preg_match_all('/^\s*\d+\s*[.)]\s+/m', $reply);

        // Bullet list items starting with - or *
        $count += preg_match_all('/^\s*[-*]\s+/m', $reply);

        // Markdown table body rows (| cell | cell |)
        $count += preg_match_all('/^\s*\|.*\|\s*$/m', $reply);

        return (int) $count;
    }

    /**
     * Decode the first JSON object found in a text response.
     *
     * @param  string  $text
     * @return array<string, mixed>|null
     */
    public function decodeJsonPayload(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeData(mixed $data): array
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data    = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        return is_array($data) ? $data : [];
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', "\n", "\n"], $text);

        $lines = array_map(
            fn($line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line),
            explode("\n", $text)
        );

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? $text);
    }
}
