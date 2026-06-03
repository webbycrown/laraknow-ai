<?php

namespace Webbycrown\LaraknowAi\Support;

class ResponseGroundingValidator
{
    /**
     * Validate a normalized assistant payload against verified tool output.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, bool $hasToolOutput = false, ?string $prompt = null): array
    {
        // Response grounding is always enabled with package defaults.

        if (! $hasToolOutput && empty($payload['data'])) {
            return $payload;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reply = $this->safeText($payload['reply'] ?? '');
        $rows = $this->rows($data);
        $sections = $this->sections($data);

        if (! empty($rows)) {
            if ($this->shouldReplaceReply($reply)) {
                $payload['reply'] = $this->shouldAnswerAsMetric($prompt)
                    ? $this->buildMetricReply($rows, $prompt)
                    : $this->buildRecordReply($rows, $prompt);
            }

            return $payload;
        }

        if (! empty($sections)) {
            if ($this->shouldReplaceReply($reply)) {
                $payload['reply'] = $this->buildReportReply($sections, $prompt);
            }

            return $payload;
        }

        if ($hasToolOutput && ! $this->looksLikeNoDataReply($reply)) {
            $payload['reply'] = $this->noMatchingRecordsMessage();
            $payload['suggestion'] = $payload['suggestion'] ?? $this->noMatchingRecordsSuggestion();
        }

        return $payload;
    }

    private function noMatchingRecordsMessage(): string
    {
        return (string) config(
            'laraknow.ui.messages.no_matching_records',
            'We were unable to locate any records matching your criteria. You may want to adjust your search terms.'
        );
    }

    private function noMatchingRecordsSuggestion(): string
    {
        return (string) config(
            'laraknow.ui.messages.no_matching_records_suggestion',
            'You may want to adjust your search terms or filters.'
        );
    }

    private function shouldReplaceReply(string $reply): bool
    {
        return true;

        return $this->contradictsData($reply) || $this->containsSpeculativeAnalytics($reply);
    }

    private function contradictsData(string $reply): bool
    {
        return (bool) preg_match(
            '/\b(no|none|zero|not any|no matching|no available|nothing|could not find|cannot find|there are no|there is no)\b/iu',
            $reply
        );
    }

    private function containsSpeculativeAnalytics(string $reply): bool
    {
        return (bool) preg_match(
            '/\b(likely|probably|may indicate|might indicate|suggests|trend|insight|recommend(?:ation)?|forecast|predict|projection|appears to|seems to)\b/iu',
            $reply
        );
    }

    private function looksLikeNoDataReply(string $reply): bool
    {
        return $reply === '' || $this->contradictsData($reply);
    }

    private function safeText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $data): array
    {
        $rows = array_is_list($data) ? $data : ($data['data'] ?? []);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            fn ($row): bool => is_array($row) && ! empty($this->scalarColumns($row))
        ));
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function sections(array $data): array
    {
        $sections = $data['sections'] ?? [];

        if (! is_array($sections)) {
            return [];
        }

        return array_values(array_filter(
            $sections,
            fn ($section): bool => is_array($section) && isset($section['data']) && is_array($section['data'])
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildRecordReply(array $rows, ?string $prompt = null): string
    {
        if ($this->shouldRenderTable($prompt)) {
            return $this->buildRecordTableReply($rows);
        }

        $heading = $this->recordReplyHeading();
        $blocks = [];

        foreach (array_slice($rows, 0, $this->maxVisibleRows()) as $index => $row) {
            $columns = $this->scalarColumns($row);
            $titleColumn = $this->recordTitleColumn($columns);
            $title = $titleColumn !== null
                ? $this->formatValue($columns[$titleColumn])
                : 'Record '.($index + 1);
            $lines = [];

            $lines[] = ($index + 1).'. **'.$title.'**';

            foreach ($columns as $column => $value) {
                if ($column === $titleColumn) {
                    continue;
                }

                $lines[] = '   - '.$this->humanColumnLabel((string) $column).': '.$this->formatValue($value);
            }

            if ($titleColumn === null && empty($columns)) {
                $lines[] = '   - No displayable fields available.';
            }

            $blocks[] = implode("\n", $lines);
        }

        return trim(($heading !== '' ? $heading."\n\n" : '').implode("\n\n", $blocks));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildMetricReply(array $rows, ?string $prompt = null): string
    {
        if (count($rows) === 1) {
            $metric = $this->metricValueFromRow($rows[0] ?? []);

            if ($metric !== null) {
                return $this->metricLabel($prompt, $metric['label']).': '.$this->formatValue($metric['value']).'.';
            }
        }

        $groupedMetricReply = $this->buildGroupedMetricReply($rows);

        if ($groupedMetricReply !== '') {
            return $groupedMetricReply;
        }

        $count = count($rows);
        $maxQueryLimit = 50;
        $label = $this->metricLabel($prompt, 'Total');
        $prefix = $count >= $maxQueryLimit ? 'At least '.$label : $label;

        return $prefix.': '.$count.'.';
    }

    /**
     * Render grouped aggregate rows, such as role/count or status/total, as a
     * breakdown instead of treating the number of returned groups as the metric.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildGroupedMetricReply(array $rows): string
    {
        $lines = [];

        foreach (array_slice($rows, 0, $this->maxVisibleRows()) as $index => $row) {
            $columns = $this->scalarColumns($row);
            $metricColumns = $this->metricColumns($columns);
            $label = $this->groupedMetricLabel($columns, array_keys($metricColumns));

            if ($label === '' || empty($metricColumns)) {
                return '';
            }

            $lines[] = ($index + 1).'. **'.$label.'**';

            foreach ($metricColumns as $column => $value) {
                $lines[] = '   - '.$this->humanColumnLabel((string) $column).': '.$this->formatValue($value);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private function metricColumns(array $columns): array
    {
        return array_filter(
            $columns,
            fn ($value, $column): bool => is_numeric($value) && $this->isMetricColumn((string) $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  array<int, string>  $metricColumns
     */
    private function groupedMetricLabel(array $columns, array $metricColumns): string
    {
        $labelParts = [];

        foreach ($columns as $column => $value) {
            if (in_array((string) $column, $metricColumns, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $labelParts[] = $this->formatValue($value);
        }

        return trim(implode(' - ', $labelParts));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{label: string, value: mixed}|null
     */
    private function metricValueFromRow(array $row): ?array
    {
        foreach ($this->scalarColumns($row) as $column => $value) {
            if (! is_numeric($value) || ! $this->isMetricColumn((string) $column)) {
                continue;
            }

            return [
                'label' => $this->humanColumnLabel((string) $column),
                'value' => $value,
            ];
        }

        if (count($row) === 1) {
            $column = (string) array_key_first($row);
            $value = $row[$column] ?? null;

            if (is_numeric($value)) {
                return [
                    'label' => $this->humanColumnLabel($column),
                    'value' => $value,
                ];
            }
        }

        return null;
    }

    private function isMetricColumn(string $column): bool
    {
        return (bool) preg_match('/\b(total|count|number|sum|avg|average|min|max|amount|revenue)\b/i', str_replace('_', ' ', $column));
    }

    private function metricLabel(?string $prompt, string $fallback): string
    {
        $prompt = mb_strtolower((string) $prompt);

        if (preg_match('/\b(room\s+types?|types?)\b/u', $prompt)) {
            return 'Total Room Types';
        }

        if (preg_match('/\b(rooms?)\b/u', $prompt)) {
            return 'Total Rooms';
        }

        if (preg_match('/\b(customers?|guests?)\b/u', $prompt)) {
            return 'Total Customers';
        }

        if (preg_match('/\b(payments?)\b/u', $prompt)) {
            return 'Total Payments';
        }

        if (preg_match('/\b(reservations?|bookings?|stays?)\b/u', $prompt)) {
            return 'Total Reservations';
        }

        return $fallback !== '' ? $fallback : 'Total';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildRecordTableReply(array $rows): string
    {
        $visibleRows = array_slice($rows, 0, $this->maxVisibleRows());
        $columns = $this->tableColumns($visibleRows);

        if (empty($columns)) {
            return $this->buildRecordReply($rows);
        }

        $lines = [''];
        $headers = array_map(fn($col) => $this->humanColumnLabel($col), $columns);
        $lines[] = '| '.implode(' | ', $headers).' |';
        $lines[] = '| '.implode(' | ', array_fill(0, count($columns), '---')).' |';

        foreach ($visibleRows as $row) {
            $values = [];

            foreach ($columns as $column) {
                $values[] = $this->escapeTableValue($this->formatValue($row[$column] ?? null));
            }

            $lines[] = '| '.implode(' | ', $values).' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function buildReportReply(array $sections, ?string $prompt = null): string
    {
        if ($this->shouldRenderShortSummary($prompt)) {
            return $this->buildShortReportSummary($sections);
        }

        $lines = ['Here is the report data:'];

        foreach ($sections as $section) {
            $label = trim((string) ($section['label'] ?? 'Report section')) ?: 'Report section';
            $rows = $this->rows((array) ($section['data'] ?? []));

            $lines[] = '';
            $lines[] = '### '.$this->humanColumnLabel($label).' ('.count($rows).' records)';

            foreach (array_slice($rows, 0, min(3, $this->maxVisibleRows())) as $row) {
                $values = [];

                foreach ($this->scalarColumns($row) as $column => $value) {
                    $values[] = '**'.$this->humanColumnLabel($column).'**: '.$this->formatValue($value);
                }

                if (! empty($values)) {
                    $lines[] = '- '.implode(' | ', $values);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function buildShortReportSummary(array $sections): string
    {
        $phrases = [];

        foreach ($sections as $section) {
            $label = trim((string) ($section['label'] ?? 'Report section')) ?: 'Report section';
            $rows = $this->rows((array) ($section['data'] ?? []));
            $parts = [];

            foreach (array_slice($rows, 0, min(3, $this->maxVisibleRows())) as $row) {
                foreach ($this->scalarColumns($row) as $column => $value) {
                    $parts[] = $this->humanColumnLabel($column).': '.$this->formatValue($value);
                }
            }

            if (! empty($parts)) {
                $label = $this->humanColumnLabel($label);

                if (count($parts) === 1 && $this->summaryPartMatchesLabel($parts[0], $label)) {
                    $phrases[] = $parts[0];
                } else {
                    $phrases[] = $label.' - '.implode(', ', $parts);
                }
            }
        }

        return empty($phrases)
            ? $this->noMatchingRecordsMessage()
            : implode('; ', $phrases).'.';
    }

    private function shouldRenderShortSummary(?string $prompt): bool
    {
        return (bool) preg_match('/\b(short|brief|concise|summary|summarize|overview|dashboard summary)\b/iu', (string) $prompt)
            && ! preg_match('/\b(table|tabular|raw|records?|rows?|details?|list|show report data)\b/iu', (string) $prompt);
    }

    private function shouldAnswerAsMetric(?string $prompt): bool
    {
        $prompt = (string) $prompt;

        if ($prompt === '') {
            return false;
        }

        if (preg_match('/\b(list|show|display|give|details?|records?|rows?|table|all)\b/iu', $prompt)) {
            return false;
        }

        return (bool) preg_match('/\b(how many|count|total|number of|sum|average|avg|min|max)\b/iu', $prompt);
    }

    private function summaryPartMatchesLabel(string $part, string $label): bool
    {
        [$partLabel] = array_pad(explode(':', $part, 2), 2, '');

        return mb_strtolower(trim($partLabel)) === mb_strtolower(trim($label));
    }

    /**
     * Check if a column name represents a raw database ID/foreign key.
     */
    private function isRawIdentifierColumn(string $column): bool
    {
        return (bool) preg_match('/(^id$|_id$|^.*able_id$)/i', strtolower($column));
    }

    /**
     * Convert snake_case/kebab-case column names to clean Title Case.
     */
    private function humanColumnLabel(string $column): string
    {
        $labels = [];
        $key = strtolower($column);

        if (is_array($labels) && isset($labels[$key]) && is_scalar($labels[$key])) {
            return trim((string) $labels[$key]);
        }

        return mb_convert_case(str_replace(['_', '-'], ' ', $column), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function scalarColumns(array $row): array
    {
        return array_filter(
            $row,
            fn ($value, $column): bool => (is_scalar($value) || $value === null)
                && ! $this->isRawIdentifierColumn((string) $column)
                && ! $this->isExcludedOutputColumn((string) $column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function isExcludedOutputColumn(string $column): bool
    {
        $columns = $this->defaultExcludedOutputColumns();

        if (! is_array($columns)) {
            return false;
        }

        return in_array(strtolower($column), array_map(
            fn ($value): string => is_scalar($value) ? strtolower(trim((string) $value)) : '',
            $columns
        ), true);
    }

    private function recordReplyHeading(): string
    {
        return '';
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'N/A';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $value = trim((string) $value);
        $maxLength = 120;

        return mb_strlen($value) > $maxLength
            ? mb_substr($value, 0, $maxLength - 3).'...'
            : $value;
    }

    /**
     * Pick a stable display title for a record without assuming any host app.
     *
     * @param  array<string, mixed>  $columns
     */
    private function recordTitleColumn(array $columns): ?string
    {
        if (empty($columns)) {
            return null;
        }

        $preferred = [
            'name',
            'title',
            'number',
            'code',
            'reference',
            'room_number',
            'room',
            'email',
        ];

        foreach ($preferred as $candidate) {
            foreach (array_keys($columns) as $column) {
                if (strtolower((string) $column) === $candidate) {
                    return (string) $column;
                }
            }
        }

        foreach (array_keys($columns) as $column) {
            $normalized = strtolower((string) $column);

            if (preg_match('/(^|_)(name|title|number|code|reference)$/i', $normalized)) {
                return (string) $column;
            }
        }

        return (string) array_key_first($columns);
    }

    private function maxVisibleRows(): int
    {
        return 5;
    }

    private function shouldRenderTable(?string $prompt): bool
    {
        return (bool) preg_match('/\b(table|tabular|grid|spreadsheet)\b/iu', (string) $prompt);
    }

    /**
     * @return array<int, string>
     */
    private function defaultExcludedOutputColumns(): array
    {
        return [
            'description',
            'information',
            'view',
            'content',
            'body',
            'notes',
            'metadata',
            'payload',
            'properties',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function tableColumns(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($this->scalarColumns($row)) as $column) {
                $column = (string) $column;

                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

    private function escapeTableValue(string $value): string
    {
        return str_replace('|', '\\|', preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
