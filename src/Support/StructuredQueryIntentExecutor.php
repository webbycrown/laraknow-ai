<?php

namespace Webbycrown\LaraknowAi\Support;

use Exception;
use Illuminate\Support\Facades\DB;

class StructuredQueryIntentExecutor
{
    /**
     * Execute a safe single-table read intent through Laravel's query builder.
     *
     * @param  array<string, mixed>  $intent
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function execute(array $intent): array
    {
        $table = $this->normalizeIdentifier($intent['table'] ?? '');

        if ($table === '') {
            throw new Exception('A table is required.');
        }

        $tableContext = $this->tableContext($table);
        $columns = $this->validatedColumns($intent['columns'] ?? ['*'], $tableContext);
        $limit = min(
            max(1, (int) ($intent['limit'] ?? 10)),
            max(1, (int) config('laraknow.max_query_limit', 50))
        );

        $query = DB::table($table)->select($columns);

        foreach ($this->filters($intent['filters'] ?? []) as $filter) {
            $rawColumn = (string) ($filter['column'] ?? '');
            $operator = $this->validateOperator((string) ($filter['operator'] ?? '='));
            $value = $filter['value'] ?? null;
            $aliasApplied = false;

            if ($operator === '=') {
                [$value, $aliasApplied] = $this->applyCategoricalValueAlias($table, $rawColumn, $value);
            }

            $column = $this->validateFilterColumn($rawColumn, $tableContext, $aliasApplied);

            if ($operator === 'like' && is_scalar($value)) {
                $value = (string) $value;
            }

            $query->where($column, $operator, $value);
        }

        foreach ($this->orderBy($intent['order_by'] ?? []) as $order) {
            $column = $this->validateFilterColumn((string) $order['column'], $tableContext);
            $direction = strtolower((string) ($order['direction'] ?? 'asc'));

            if (! in_array($direction, ['asc', 'desc'], true)) {
                throw new Exception('Unsupported order direction ['.$direction.'].');
            }

            $query->orderBy($column, $direction);
        }

        return $this->filterBlockedResultColumns(
            $query->limit($limit)->get(),
            $table,
            $tableContext['blocked_columns']
        );
    }

    /**
     * @return array{columns: array<int, string>, safe_columns: array<int, string>, blocked_columns: array<int, string>}
     *
     * @throws Exception
     */
    private function tableContext(string $table): array
    {
        $allowedTables = (new TableAccessResolver)->allowedTables();

        if (empty($allowedTables)) {
            throw new Exception('No allowed tables are configured.');
        }

        if (! in_array($table, $allowedTables, true)) {
            throw new Exception("Table [{$table}] is not allowed.");
        }

        $sensitiveData = new SensitiveDataPolicy;
        $schema = new DatabaseSchemaInspector($sensitiveData);

        if (! $schema->hasTable($table)) {
            throw new Exception("Table [{$table}] does not exist.");
        }

        $blockedColumns = (new AssistantConfig)->blockedColumns();
        $columns = array_map(
            fn ($column): string => $this->normalizeIdentifier((string) $column),
            $schema->columns($table)
        );

        $safeColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => ! in_array($column, $blockedColumns, true)
                && ! $sensitiveData->isBlockedColumn($table, $column)
        ));

        return [
            'columns' => $columns,
            'safe_columns' => $safeColumns,
            'blocked_columns' => $blockedColumns,
        ];
    }

    /**
     * @param  mixed  $columns
     * @param  array{columns: array<int, string>, safe_columns: array<int, string>, blocked_columns: array<int, string>}  $tableContext
     * @return array<int, string>
     *
     * @throws Exception
     */
    private function validatedColumns(mixed $columns, array $tableContext): array
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        if (! is_array($columns) || empty($columns)) {
            throw new Exception('At least one column is required.');
        }

        $columns = array_values(array_unique(array_filter(array_map(
            fn ($column): string => is_scalar($column) ? $this->normalizeIdentifier((string) $column) : '',
            $columns
        ))));

        if (in_array('*', $columns, true)) {
            return $tableContext['safe_columns'];
        }

        $invalid = array_values(array_diff($columns, $tableContext['safe_columns']));

        if (! empty($invalid)) {
            throw new Exception('Unknown or unsafe columns requested: '.implode(', ', $invalid).'. Available safe columns: '.implode(', ', $tableContext['safe_columns']));
        }

        return $columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function filters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        return array_values(array_filter($filters, fn ($filter): bool => is_array($filter)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orderBy(mixed $orders): array
    {
        if (! is_array($orders)) {
            return [];
        }

        return array_values(array_filter($orders, fn ($order): bool => is_array($order)));
    }

    /**
     * @param  array{columns: array<int, string>, safe_columns: array<int, string>, blocked_columns: array<int, string>}  $tableContext
     *
     * @throws Exception
     */
    private function validateFilterColumn(string $column, array $tableContext, bool $allowInternalAliasFilter = false): string
    {
        $column = $this->normalizeIdentifier($column);

        if ($column === '' || str_contains($column, '.')) {
            throw new Exception('Structured query intents support same-table columns only.');
        }

        if (! in_array($column, $tableContext['columns'], true)) {
            throw new Exception('Unknown column ['.$column.']. Available safe columns: '.implode(', ', $tableContext['safe_columns']));
        }

        if (! in_array($column, $tableContext['safe_columns'], true) && ! $allowInternalAliasFilter) {
            throw new Exception('Blocked filter column requested.');
        }

        return $column;
    }

    /**
     * Apply configured user-facing aliases to structured filters.
     *
     * @return array{0: mixed, 1: bool}
     */
    private function applyCategoricalValueAlias(string $table, string $column, mixed $value): array
    {
        if (! is_scalar($value)) {
            return [$value, false];
        }

        $column = $this->normalizeIdentifier($column);
        $normalizedValue = strtolower(trim((string) $value));

        if ($column === '' || $normalizedValue === '') {
            return [$value, false];
        }

        $rules = config('laraknow.categorical_value_aliases', []);

        if (! is_array($rules) || empty($rules)) {
            return [$value, false];
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $tables = array_map('strtolower', array_filter((array) ($rule['tables'] ?? []), 'is_string'));
            $columns = array_map('strtolower', array_filter((array) ($rule['columns'] ?? []), 'is_string'));
            $values = (array) ($rule['values'] ?? []);

            if (! in_array($table, $tables, true) || ! in_array($column, $columns, true)) {
                continue;
            }

            foreach ($values as $from => $to) {
                if (! is_scalar($from) || ! is_scalar($to)) {
                    continue;
                }

                if (strtolower(trim((string) $from)) === $normalizedValue) {
                    return [$to, true];
                }
            }
        }

        return [$value, false];
    }

    /**
     * @throws Exception
     */
    private function validateOperator(string $operator): string
    {
        $operator = strtolower(trim($operator));

        if (! in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'like'], true)) {
            throw new Exception('Unsupported filter operator ['.$operator.'].');
        }

        return $operator;
    }

    /**
     * @param  iterable<int, mixed>  $rows
     * @param  array<int, string>  $blockedColumns
     * @return array<int, array<string, mixed>>
     */
    private function filterBlockedResultColumns(iterable $rows, string $table, array $blockedColumns): array
    {
        $sensitiveData = new SensitiveDataPolicy;

        return collect($rows)
            ->map(function ($row) use ($blockedColumns, $sensitiveData, $table) {
                $values = (array) $row;

                foreach (array_keys($values) as $column) {
                    if (
                        in_array(strtolower((string) $column), $blockedColumns, true)
                        || $sensitiveData->isBlockedColumn($table, (string) $column)
                    ) {
                        unset($values[$column]);
                    }
                }

                return $values;
            })
            ->all();
    }

    private function normalizeIdentifier(mixed $identifier): string
    {
        return strtolower(trim((string) $identifier, " \t\n\r\0\x0B`"));
    }
}
