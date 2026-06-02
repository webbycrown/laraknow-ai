<?php

namespace Webbycrown\LaraknowAi\Support;

class ReportReplyBuilder
{
    /**
     * Build a compact readable fallback from DatabaseReportTool data.
     *
     * @param  array<int|string, mixed>  $data
     */
    public function build(array $data): string
    {
        $sections = $data['sections'] ?? null;

        if (! is_array($sections) || empty($sections)) {
            return '';
        }

        $lines = ['Available report data:'];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $label = $this->humanLabel((string) ($section['label'] ?? 'Report section'));
            $rows = $section['data'] ?? [];

            $lines[] = '';
            $lines[] = "**{$label}**";

            if (! is_array($rows) || empty($rows)) {
                $lines[] = 'No matching records are available.';
                continue;
            }

            $rows = array_values($rows);

            foreach (array_slice($rows, 0, 10) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $line = $this->rowSummary($row);

                if ($line !== '') {
                    $lines[] = '- '.$line;
                }
            }
        }

        return trim(implode(PHP_EOL, $lines));
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    public function replyNeedsReportData(string $reply, array $data = []): bool
    {
        $text = trim(strip_tags($reply));

        if ($text === '') {
            return true;
        }

        $withoutFormatting = preg_replace('/[#*_`\-\s:0-9.]+/u', '', $text) ?? $text;

        if (mb_strlen(trim($withoutFormatting)) < 80) {
            return true;
        }

        $sectionCount = preg_match_all('/^\s*#{2,4}\s+/m', $reply);
        $placeholderCount = preg_match_all('/^\s*-{3,}\s*$/m', $reply);

        if ($sectionCount > 0 && $placeholderCount >= max(1, $sectionCount - 1)) {
            return true;
        }

        return ! empty($data) && $this->replyIsMissingSectionValues($reply, $data);
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function replyIsMissingSectionValues(string $reply, array $data): bool
    {
        $sections = $data['sections'] ?? null;

        if (! is_array($sections) || empty($sections)) {
            return false;
        }

        $reply = mb_strtolower($reply);
        $sectionsWithMissingValues = 0;

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $rows = $section['data'] ?? [];

            if (! is_array($rows) || empty($rows)) {
                continue;
            }

            if (! $this->replyContainsAnyRowValue($reply, array_values($rows))) {
                $sectionsWithMissingValues++;
            }
        }

        return $sectionsWithMissingValues > 0;
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function replyContainsAnyRowValue(string $reply, array $rows): bool
    {
        foreach (array_slice($rows, 0, 3) as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $value) {
                if (is_array($value) || is_object($value) || $value === null || $value === '') {
                    continue;
                }

                $value = mb_strtolower((string) $value);

                if (mb_strlen($value) >= 2 && str_contains($reply, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function rowSummary(array $row): string
    {
        $parts = [];

        foreach ($row as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $parts[] = $this->humanLabel((string) $key).': '.$this->displayValue($value);
        }

        return implode(', ', $parts);
    }

    private function humanLabel(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));

        if ($value === '') {
            return 'Report section';
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not available';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

}
