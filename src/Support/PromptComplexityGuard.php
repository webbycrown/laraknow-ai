<?php

namespace Webbycrown\LaraknowAi\Support;

class PromptComplexityGuard
{
    /**
     * Return a validation-style payload when a prompt is too expensive to run.
     *
     * @return array<string, mixed>|null
     */
    public function inspect(string $prompt): ?array
    {
        $prompt = trim($prompt);
        $length = mb_strlen($prompt);
        $maxLength = max(1, (int) config('laraknow.max_prompt_length', 2000));

        if ($length > $maxLength) {
            return [
                'message' => 'The prompt is too long.',
                'suggestion' => 'Please shorten your question or split it into smaller requests.',
                'errors' => [
                    'prompt' => [
                        "The prompt may not be greater than {$maxLength} characters.",
                    ],
                ],
            ];
        }

        if (! (bool) config('laraknow.broad_prompt_guard.enabled', true)) {
            return null;
        }

        if (! $this->isBroadAnalyticsPrompt($prompt)) {
            return null;
        }

        return [
            'message' => 'The request is too broad to answer safely in one step.',
            'suggestion' => 'Please ask for one focused report or choose a few specific metrics.',
            'errors' => [
                'prompt' => [
                    'The request is too broad. Please narrow the report, dashboard, or analytics scope.',
                ],
            ],
        ];
    }

    private function isBroadAnalyticsPrompt(string $prompt): bool
    {
        $lowerPrompt = mb_strtolower($prompt);
        $minLength = max(1, (int) config('laraknow.broad_prompt_guard.min_prompt_length', 240));
        $maxSections = max(1, (int) config('laraknow.broad_prompt_guard.max_requested_sections', 3));

        $broadTerms = [
            'all reports',
            'all analytics',
            'complete report',
            'complete analytics',
            'complete dashboard',
            'full report',
            'full analytics',
            'full dashboard',
            'entire report',
            'entire dashboard',
            'everything',
            'every metric',
            'all metrics',
            'all aggregate',
            'all aggregates',
        ];

        foreach ($broadTerms as $term) {
            if (str_contains($lowerPrompt, $term)) {
                return true;
            }
        }

        if (mb_strlen($prompt) < $minLength) {
            return false;
        }

        if ($this->topicSegmentCount($prompt) > $maxSections) {
            return true;
        }

        $analyticsTerms = [
            'aggregate',
            'analytics',
            'dashboard',
            'report',
            'summary',
            'count',
            'total',
            'average',
            'compare',
            'breakdown',
            'group by',
            'trend',
            'chart',
            'metrics',
        ];

        $matchedTerms = 0;

        foreach ($analyticsTerms as $term) {
            if (str_contains($lowerPrompt, $term)) {
                $matchedTerms++;
            }
        }

        if ($matchedTerms < 3) {
            return false;
        }

        return $this->requestedSectionCount($prompt) > $maxSections;
    }

    private function requestedSectionCount(string $prompt): int
    {
        $matches = 0;
        $matches += preg_match_all('/(?:^|[\n,;])\s*(?:and\s+)?(?:also\s+)?(?:show|give|get|include|count|total|compare|list|summarize|calculate)\b/iu', $prompt);
        $matches += preg_match_all('/\b(?:count|total|average|compare|breakdown|trend|summary|report)\b/iu', $prompt);

        return (int) $matches;
    }

    private function topicSegmentCount(string $prompt): int
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($prompt)) ?? $prompt;
        $segments = preg_split('/(?:,|;|\band\b|\bwith\b|\bcovering\b|\binclude\b|\bincluding\b)/iu', $normalized) ?: [];

        $segments = array_filter(array_map('trim', $segments), function (string $segment) {
            return mb_strlen($segment) >= 4
                && ! preg_match('/^(please|give me|show me|i want|can you|could you)$/iu', $segment);
        });

        return count($segments);
    }
}
