<?php

namespace Webbycrown\LaraknowAi\Support;

class SchemaContextFilter
{
    /**
     * Return the allowed tables most relevant to the latest user prompt.
     *
     * @param  array<int, string>  $allowedTables
     * @return array<int, string>
     */
    public function relevantTables(?string $prompt, array $allowedTables): array
    {
        $allowedTables = array_values(array_filter(array_map(
            fn ($table): string => is_scalar($table) ? strtolower(trim((string) $table)) : '',
            $allowedTables
        )));

        if (empty($allowedTables) || ! (bool) config('laraknow.schema_filtering.enabled', true)) {
            return $allowedTables;
        }

        $prompt = mb_strtolower(trim((string) $prompt));

        if ($prompt === '') {
            return $this->fallbackTables($allowedTables);
        }

        $matches = [];

        foreach ($allowedTables as $table) {
            foreach ($this->termsForTable($table) as $term) {
                if ($term !== '' && preg_match('/\b'.preg_quote($term, '/').'\b/iu', $prompt)) {
                    $matches[] = $table;
                    break;
                }
            }
        }

        if (empty($matches)) {
            return $this->fallbackTables($allowedTables);
        }

        return array_slice(
            array_values(array_unique($matches)),
            0,
            $this->maxPromptTables()
        );
    }

    /**
     * @param  array<int, string>  $allowedTables
     * @return array<int, string>
     */
    public function filterRequestedTables(string $requestedTables, array $allowedTables): array
    {
        $requested = array_values(array_filter(array_map(
            fn ($table): string => strtolower(trim((string) $table, " \t\n\r\0\x0B`")),
            preg_split('/[,\s]+/', $requestedTables) ?: []
        )));

        if (empty($requested)) {
            return [];
        }

        $allowedLookup = array_flip(array_map('strtolower', $allowedTables));
        $tables = [];

        foreach ($requested as $table) {
            if (isset($allowedLookup[$table])) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @param  array<int, string>  $allowedTables
     * @return array<int, string>
     */
    private function fallbackTables(array $allowedTables): array
    {
        if ((bool) config('laraknow.schema_filtering.include_all_when_unmatched', true)) {
            return array_slice($allowedTables, 0, $this->maxPromptTables());
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function termsForTable(string $table): array
    {
        $terms = [$table];
        $base = str_replace('_', ' ', $table);
        $terms[] = $base;
        $terms[] = rtrim($base, 's');

        $aliases = config('laraknow.schema_filtering.table_aliases', []);

        if (is_array($aliases)) {
            foreach ((array) ($aliases[$table] ?? []) as $alias) {
                if (is_scalar($alias)) {
                    $terms[] = mb_strtolower(trim((string) $alias));
                }
            }
        }

        foreach ($terms as $term) {
            if ($term !== '' && ! str_ends_with($term, 's')) {
                $terms[] = $term.'s';
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }

    private function maxPromptTables(): int
    {
        return max(1, (int) config('laraknow.schema_filtering.max_prompt_tables', 8));
    }
}
