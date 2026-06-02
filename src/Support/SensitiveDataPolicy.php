<?php

namespace Webbycrown\LaraknowAi\Support;

class SensitiveDataPolicy
{
    public function __construct(
        private ?AssistantConfig $config = null
    ) {
        $this->config ??= new AssistantConfig;
    }

    public function isBlockedColumn(string $table, string $column): bool
    {
        $column = strtolower($column);

        if (in_array($column, $this->config->blockedColumns(), true)) {
            return true;
        }

        foreach ($this->blockedColumnsForTable($table) as $blockedColumn) {
            if ($column === $blockedColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    public function blockedColumnsForTables(array $tables): array
    {
        $columns = $this->config->blockedColumns();

        foreach ($tables as $table) {
            $columns = array_merge($columns, $this->blockedColumnsForTable($table));
        }

        return array_values(array_unique(array_map('strtolower', $columns)));
    }

    /**
     * @return array<int, string>
     */
    private function blockedColumnsForTable(string $table): array
    {
        $table = strtolower($table);
        $configured = $this->config->blockedTableColumns();

        return $configured[$table] ?? [];
    }
}
