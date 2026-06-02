<?php

namespace Webbycrown\LaraknowAi\Support;

use Exception;

class SqlSafetyValidator
{
    /**
     * @var array<int, string>
     */
    private array $reservedWords = [
        'as',
        'and',
        'or',
        'on',
        'in',
        'is',
        'like',
        'between',
        'exists',
        'not',
        'null',
        'select',
        'from',
        'join',
        'left',
        'right',
        'inner',
        'outer',
        'full',
        'cross',
        'where',
        'group',
        'by',
        'order',
        'having',
        'limit',
        'offset',
        'case',
        'when',
        'then',
        'else',
        'end',
        'distinct',
        'asc',
        'desc',
        'count',
        'sum',
        'avg',
        'min',
        'max',
        'coalesce',
        'ifnull',
        'date',
        'current_date',
        'current_time',
        'current_timestamp',
        'curdate',
        'now',
        'year',
        'month',
        'day',
        'interval',
        'true',
        'false',
    ];

    /**
     * Accumulated query bindings for tenant scoping.
     *
     * @var array
     */
    private array $bindings = [];

    public function __construct(
        private ?AssistantConfig $config = null,
        private ?TableAccessResolver $tables = null,
        private ?SensitiveDataPolicy $sensitiveData = null,
        private ?DatabaseSchemaInspector $schema = null
    ) {
        $this->config ??= new AssistantConfig;
        $this->tables ??= new TableAccessResolver($this->config);
        $this->sensitiveData ??= new SensitiveDataPolicy($this->config);
        $this->schema ??= new DatabaseSchemaInspector($this->sensitiveData);
    }

    /**
     * Get the accumulated query bindings.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Validate a model-generated SQL query and append a safe limit when needed.
     *
     * @throws Exception
     */
    public function validateSelect(string $query): string
    {
        $this->bindings = [];
        $query = rtrim(trim($query), " \t\n\r\0\x0B;");

        if ($this->shouldUseParser()) {
            return $this->validateSelectWithParser($query);
        }

        return $this->validateSelectWithLegacyValidator($query);
    }

    /**
     * Validate a SELECT query with the legacy regex/token validator.
     *
     * @throws Exception
     */
    private function validateSelectWithLegacyValidator(string $query): string
    {
        $queryWithoutLiterals = $this->withoutStringLiterals($query);
        $lowerQuery = strtolower($queryWithoutLiterals);

        if ($query === '' || ! str_starts_with($lowerQuery, 'select')) {
            throw new Exception('Only SELECT queries are allowed.');
        }

        $this->rejectBlockedKeywords($lowerQuery);

        $tableContext = $this->tableContext($queryWithoutLiterals);

        if (empty($tableContext['tables'])) {
            throw new Exception('No allowed table was referenced in the query.');
        }

        $this->validateQualifiedColumns($queryWithoutLiterals, $tableContext);
        $this->validateSelectedColumns($queryWithoutLiterals, $tableContext);
        $this->validateUnqualifiedNonSelectColumns($queryWithoutLiterals, $tableContext);

        $query = $this->applyCategoricalValueAliases($query, $tableContext);
        $query = $this->applyNumericValueScaling($query, $tableContext);

        if (! $this->containsSqlIdentifier($lowerQuery, 'limit')) {
            $query .= ' LIMIT '.(int) config('laraknow.max_query_limit', 50);
        }

        return $query;
    }

    /**
     * Validate a SELECT query using an optional SQL parser package.
     *
     * The parser is intentionally feature-flagged so hosts can adopt it
     * gradually. In parser mode, parser failures are blocking; in auto mode,
     * only a missing parser package falls back to the legacy validator.
     *
     * @throws Exception
     */
    private function validateSelectWithParser(string $query): string
    {
        if (! class_exists('\\PhpMyAdmin\\SqlParser\\Parser')) {
            if ($this->sqlValidationMode() === 'parser') {
                throw new Exception('SQL parser validation is enabled, but no supported SQL parser is installed.');
            }

            return $this->validateSelectWithLegacyValidator($query);
        }

        $parserClass = '\\PhpMyAdmin\\SqlParser\\Parser';
        $selectStatementClass = '\\PhpMyAdmin\\SqlParser\\Statements\\SelectStatement';
        $parser = new $parserClass($query);

        if (! empty($parser->errors)) {
            throw new Exception('Unable to parse SQL query safely.');
        }

        $statements = is_array($parser->statements ?? null) ? $parser->statements : [];

        if (count($statements) !== 1) {
            throw new Exception('Only one SELECT query is allowed.');
        }

        $statement = $statements[0];

        if (! $statement instanceof $selectStatementClass) {
            throw new Exception('Only SELECT queries are allowed.');
        }

        if ($this->containsNestedParsedStatement($statement)) {
            throw new Exception('Nested SQL queries are not allowed.');
        }

        $queryWithoutLiterals = $this->withoutStringLiterals($query);
        $lowerQuery = strtolower($queryWithoutLiterals);

        $this->rejectBlockedKeywords($lowerQuery);

        $tableContext = $this->tableContextFromParsedStatement($statement);

        if (empty($tableContext['tables'])) {
            throw new Exception('No allowed table was referenced in the query.');
        }

        $this->validateParsedColumnReferences($statement, $tableContext);
        $this->validateParsedSelectedColumns($statement, $tableContext);

        $query = $this->applyCategoricalValueAliases($query, $tableContext);
        $query = $this->applyNumericValueScaling($query, $tableContext);

        if (! $this->containsSqlIdentifier($lowerQuery, 'limit')) {
            $query .= ' LIMIT '.(int) config('laraknow.max_query_limit', 50);
        }

        return $query;
    }

    /**
     * Remove configured blocked fields from rows returned by SQL tools.
     *
     * @param  iterable<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function filterBlockedResultColumns(iterable $rows): array
    {
        $blockedColumns = $this->config->blockedColumns();

        return collect($rows)
            ->map(function ($row) use ($blockedColumns) {
                $values = (array) $row;

                foreach (array_keys($values) as $column) {
                    if (in_array(strtolower((string) $column), $blockedColumns, true)) {
                        unset($values[$column]);
                    }
                }

                return $values;
            })
            ->all();
    }

    public static function safeErrorMessage(string $message, string $fallback): string
    {
        foreach ([
            'Only SELECT queries are allowed.',
            'No allowed table',
            'No allowed tables',
            'Table [',
            'Unknown table alias',
            'Unknown column',
            'Unknown selected column',
            'Ambiguous selected column',
            'Blocked column',
            'Wildcard SELECT',
            'Restricted SQL keyword',
            'SQL parser validation',
            'Unable to parse SQL query safely.',
            'Only one SELECT query is allowed.',
            'Nested SQL queries are not allowed.',
        ] as $safePrefix) {
            if (str_starts_with($message, $safePrefix)) {
                return $message;
            }
        }

        return $fallback;
    }

    private function shouldUseParser(): bool
    {
        return in_array($this->sqlValidationMode(), ['auto', 'parser'], true);
    }

    private function sqlValidationMode(): string
    {
        $mode = strtolower((string) config('laraknow.sql_validation.mode', 'legacy'));

        return in_array($mode, ['legacy', 'auto', 'parser'], true) ? $mode : 'legacy';
    }

    /**
     * Apply configured aliases for categorical SQL filter values.
     *
     * @param  array<string, mixed>  $tableContext
     */
    private function applyCategoricalValueAliases(string $query, array $tableContext): string
    {
        $rules = config('laraknow.categorical_value_aliases', []);

        if (! is_array($rules) || empty($rules)) {
            return $query;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $tables = array_map('strtolower', array_filter((array) ($rule['tables'] ?? []), 'is_string'));
            $columns = array_map('strtolower', array_filter((array) ($rule['columns'] ?? []), 'is_string'));
            $aliases = $this->normalizedValueAliases((array) ($rule['values'] ?? []));

            if (empty($tables) || empty($columns) || empty($aliases)) {
                continue;
            }

            foreach ($tableContext['aliases'] as $alias => $table) {
                if (! in_array($table, $tables, true)) {
                    continue;
                }

                foreach ($columns as $column) {
                    $query = $this->replaceQualifiedStringComparisons($query, $alias, $column, $aliases);
                }
            }
        }

        return $query;
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string, string>
     */
    private function normalizedValueAliases(array $values): array
    {
        $aliases = [];

        foreach ($values as $from => $to) {
            if (! is_scalar($from) || ! is_scalar($to)) {
                continue;
            }

            $from = strtolower(trim((string) $from));
            $to = trim((string) $to);

            if ($from === '' || $to === '') {
                continue;
            }

            $aliases[$from] = $to;
        }

        return $aliases;
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private function replaceQualifiedStringComparisons(string $query, string $qualifier, string $column, array $aliases): string
    {
        $pattern = '/(`?'.preg_quote($qualifier, '/').'`?\s*\.\s*`?'.preg_quote($column, '/').'`?\s*=\s*)([\'"])(.*?)\2/isu';

        return preg_replace_callback($pattern, function (array $matches) use ($aliases): string {
            $value = strtolower(trim((string) $matches[3]));

            if (! array_key_exists($value, $aliases)) {
                return $matches[0];
            }

            return $matches[1].$matches[2].str_replace($matches[2], $matches[2].$matches[2], $aliases[$value]).$matches[2];
        }, $query) ?? $query;
    }

    /**
     * Apply configured user-facing to stored numeric conversions on safe filters.
     *
     * @param  array<string, mixed>  $tableContext
     */
    private function applyNumericValueScaling(string $query, array $tableContext): string
    {
        $rules = config('laraknow.numeric_value_scaling', []);

        if (! is_array($rules) || empty($rules)) {
            return $query;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $tables = array_map('strtolower', array_filter((array) ($rule['tables'] ?? []), 'is_string'));
            $columns = array_map('strtolower', array_filter((array) ($rule['columns'] ?? []), 'is_string'));
            $multiplier = (float) ($rule['input_multiplier'] ?? 1);

            if (empty($tables) || empty($columns) || $multiplier <= 0 || $multiplier === 1.0) {
                continue;
            }

            foreach ($tableContext['aliases'] as $alias => $table) {
                if (! in_array($table, $tables, true)) {
                    continue;
                }

                foreach ($columns as $column) {
                    $query = $this->scaleQualifiedNumericComparisons($query, $alias, $column, $multiplier);
                }
            }
        }

        return $query;
    }

    private function scaleQualifiedNumericComparisons(string $query, string $qualifier, string $column, float $multiplier): string
    {
        $pattern = '/(`?'.preg_quote($qualifier, '/').'`?\s*\.\s*`?'.preg_quote($column, '/').'`?\s*(?:<=|>=|=|<|>)\s*)(\d+(?:\.\d+)?)(?!\s*[+\-*\/])/i';

        return preg_replace_callback($pattern, function (array $matches) use ($multiplier): string {
            $originalValue = (float) $matches[2];
            $scaledValue = $originalValue * $multiplier;

            if (floor($scaledValue) === $scaledValue) {
                $scaledValue = (string) (int) $scaledValue;
            } else {
                $scaledValue = rtrim(rtrim(number_format($scaledValue, 6, '.', ''), '0'), '.');
            }

            return $matches[1].$scaledValue;
        }, $query) ?? $query;
    }

    /**
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function validateQualifiedColumns(string $query, array $tableContext): void
    {
        preg_match_all(
            '/`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\.\s*`?([a-zA-Z_][a-zA-Z0-9_]*|\*)`?/i',
            $query,
            $matches,
            PREG_SET_ORDER
        );

        $selectClause = strtolower($this->selectClause($query));

        foreach ($matches as $match) {
            $qualifier = $this->normalizeIdentifier($match[1]);
            $column = $this->normalizeIdentifier($match[2]);
            $table = $tableContext['aliases'][$qualifier] ?? null;

            if (! $table) {
                throw new Exception("Unknown table alias [{$qualifier}] in SQL query.");
            }

            if ($column === '*') {
                if (str_contains($selectClause, strtolower($match[0]))) {
                    throw new Exception('Wildcard SELECT is not allowed. Select explicit safe columns or use COUNT(*).');
                }

                continue;
            }

            if (! in_array($column, $tableContext['columns'][$table], true)) {
                throw new Exception($this->unknownColumnMessage($table, $column, $tableContext));
            }

            if (
                str_contains($selectClause, strtolower($match[0]))
                && $this->isBlockedColumn($table, $column)
            ) {
                if ($this->isQualifiedReferenceOnlyCounted($query, $match[0])) {
                    continue;
                }

                throw new Exception("Blocked column [{$table}.{$column}] cannot be selected. It may only be used internally for joins or filters when needed.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function validateSelectedColumns(string $query, array $tableContext): void
    {
        foreach ($this->splitSelectExpressions($this->selectClause($query)) as $expression) {
            $expression = trim($expression);

            if ($expression === '') {
                continue;
            }

            if ($this->isCountWildcard($expression)) {
                continue;
            }

            if ($this->isCountColumnAggregate($expression)) {
                continue;
            }

            if (preg_match('/(^|[^a-zA-Z0-9_])\*([^a-zA-Z0-9_]|$)/', $expression)) {
                throw new Exception('Wildcard SELECT is not allowed. Select explicit safe columns or use COUNT(*).');
            }

            if (str_contains($expression, '.')) {
                continue;
            }

            $expressionWithoutAlias = preg_replace('/\s+as\s+`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*$/i', '', $expression) ?? $expression;
            $expressionWithoutAlias = preg_replace('/\s+`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*$/i', '', $expressionWithoutAlias) ?? $expressionWithoutAlias;

            preg_match_all('/`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $expressionWithoutAlias, $matches);

            foreach (array_unique($matches[1] ?? []) as $identifier) {
                $identifier = $this->normalizeIdentifier($identifier);

                if ($identifier === '' || in_array($identifier, $this->reservedWords, true)) {
                    continue;
                }

                $this->validateUnqualifiedSelectedIdentifier($identifier, $tableContext);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function validateUnqualifiedSelectedIdentifier(string $identifier, array $tableContext): void
    {
        $matches = [];

        foreach ($tableContext['tables'] as $table) {
            if (in_array($identifier, $tableContext['columns'][$table], true)) {
                $matches[] = $table;
            }
        }

        if (empty($matches)) {
            throw new Exception('Unknown selected column ['.$identifier.']. Available safe columns: '.$this->availableSafeColumnsSummary($tableContext));
        }

        if (count($matches) > 1) {
            throw new Exception('Ambiguous selected column ['.$identifier.']. Qualify it with a table name or alias.');
        }

        if ($this->isBlockedColumn($matches[0], $identifier)) {
            throw new Exception("Blocked column [{$matches[0]}.{$identifier}] cannot be selected. It may only be used internally for joins or filters when needed.");
        }
    }

    /**
     * Validate unqualified identifiers used after FROM, especially WHERE,
     * GROUP BY, ORDER BY, and HAVING columns.
     *
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function validateUnqualifiedNonSelectColumns(string $query, array $tableContext): void
    {
        $clause = $this->nonSelectClause($query);

        if ($clause === '') {
            return;
        }

        $clause = preg_replace(
            '/`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\.\s*`?[a-zA-Z_][a-zA-Z0-9_]*`?/i',
            ' ',
            $clause
        ) ?? $clause;

        preg_match_all('/`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?=\s*\()/i', $clause, $functionMatches);
        $functionNames = array_map(
            fn ($identifier) => $this->normalizeIdentifier((string) $identifier),
            $functionMatches[1] ?? []
        );

        preg_match_all('/`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $clause, $matches);

        foreach (array_unique($matches[1] ?? []) as $identifier) {
            $identifier = $this->normalizeIdentifier($identifier);

            if (
                $identifier === ''
                || in_array($identifier, $this->reservedWords, true)
                || in_array($identifier, $tableContext['tables'], true)
                || array_key_exists($identifier, $tableContext['aliases'])
                || in_array($identifier, $this->selectedAliases($query), true)
                || in_array($identifier, $functionNames, true)
            ) {
                continue;
            }

            $this->validateUnqualifiedQueryIdentifier($identifier, $tableContext);
        }
    }

    /**
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function validateUnqualifiedQueryIdentifier(string $identifier, array $tableContext): void
    {
        $matches = [];

        foreach ($tableContext['tables'] as $table) {
            if (in_array($identifier, $tableContext['columns'][$table], true)) {
                $matches[] = $table;
            }
        }

        if (empty($matches)) {
            throw new Exception('Unknown column ['.$identifier.']. Available safe columns: '.$this->availableSafeColumnsSummary($tableContext));
        }

        if (count($matches) > 1) {
            throw new Exception('Ambiguous column ['.$identifier.']. Qualify it with a table name or alias.');
        }

        if ($this->isBlockedColumn($matches[0], $identifier)) {
            throw new Exception("Blocked column [{$matches[0]}.{$identifier}] cannot be selected. It may only be used internally for joins or filters when needed.");
        }
    }

    /**
     * @return array{tables: array<int, string>, aliases: array<string, string>, columns: array<string, array<int, string>>, safe_columns: array<string, array<int, string>>}
     *
     * @throws Exception
     */
    private function tableContextFromParsedStatement(object $statement): array
    {
        $allowedTables = $this->tables->allowedTables();

        if (empty($allowedTables)) {
            throw new Exception('No allowed tables are configured.');
        }

        $tableRefs = $this->parsedTableReferences($statement);
        $tables = [];
        $aliases = [];
        $columns = [];
        $safeColumns = [];

        foreach ($tableRefs as $ref) {
            $table = $this->normalizeIdentifier((string) ($ref['table'] ?? ''));
            $alias = $this->normalizeIdentifier((string) ($ref['alias'] ?? ''));

            if ($table === '') {
                continue;
            }

            if (! in_array($table, $allowedTables, true)) {
                throw new Exception("Table [{$table}] is not allowed.");
            }

            if (! $this->schema->hasTable($table)) {
                throw new Exception("Table [{$table}] does not exist.");
            }

            $tables[] = $table;
            $aliases[$table] = $table;

            if ($alias !== '' && ! in_array($alias, $this->reservedWords, true)) {
                $aliases[$alias] = $table;
            }

            $columns[$table] = array_map(
                fn ($column) => $this->normalizeIdentifier((string) $column),
                $this->schema->columns($table)
            );

            $safeColumns[$table] = array_values(array_filter(
                $columns[$table],
                fn ($column) => ! $this->isBlockedColumn($table, $column)
            ));
        }

        return [
            'tables' => array_values(array_unique($tables)),
            'aliases' => $aliases,
            'columns' => $columns,
            'safe_columns' => $safeColumns,
        ];
    }

    /**
     * @return array<int, array{table: string, alias: string}>
     */
    private function parsedTableReferences(object $statement): array
    {
        $references = [];

        foreach (['from', 'join'] as $property) {
            foreach ($this->arrayValue($statement->{$property} ?? []) as $node) {
                foreach ($this->parsedTableReferencesFromNode($node) as $reference) {
                    $references[] = $reference;
                }
            }
        }

        return $this->uniqueParsedTableReferences($references);
    }

    /**
     * @return array<int, array{table: string, alias: string}>
     */
    private function parsedTableReferencesFromNode(mixed $node): array
    {
        if (! is_object($node)) {
            return [];
        }

        $references = [];
        $table = $this->normalizeIdentifier((string) ($node->table ?? ''));
        $column = $this->normalizeIdentifier((string) ($node->column ?? ''));

        if ($table !== '' && $column === '') {
            $references[] = [
                'table' => $table,
                'alias' => $this->normalizeParsedAlias($node->alias ?? ''),
            ];
        }

        $aliasValue = $node->alias ?? null;

        foreach (get_object_vars($node) as $value) {
            if ($value === $aliasValue) {
                continue;
            }

            foreach ($this->arrayValue($value) as $child) {
                foreach ($this->parsedTableReferencesFromNode($child) as $reference) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /**
     * @param  array<int, array{table: string, alias: string}>  $references
     * @return array<int, array{table: string, alias: string}>
     */
    private function uniqueParsedTableReferences(array $references): array
    {
        $unique = [];

        foreach ($references as $reference) {
            $key = $reference['table'].'|'.$reference['alias'];
            $unique[$key] = $reference;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function validateParsedColumnReferences(object $statement, array $tableContext): void
    {
        foreach ($this->parsedColumnReferences($statement) as $reference) {
            $column = $this->normalizeIdentifier((string) ($reference['column'] ?? ''));
            $qualifier = $this->normalizeIdentifier((string) ($reference['qualifier'] ?? ''));

            if ($column === '') {
                continue;
            }

            if ($column === '*') {
                continue;
            }

            $table = null;

            if ($qualifier !== '') {
                $table = $tableContext['aliases'][$qualifier] ?? null;

                if (! $table) {
                    throw new Exception("Unknown table alias [{$qualifier}] in SQL query.");
                }

                if (! in_array($column, $tableContext['columns'][$table] ?? [], true)) {
                    throw new Exception($this->unknownColumnMessage($table, $column, $tableContext));
                }
            } else {
                $table = $this->resolveUnqualifiedParsedColumn($column, $tableContext);
            }

            if (
                ! empty($reference['select'])
                && $this->isBlockedColumn($table, $column)
            ) {
                throw new Exception("Blocked column [{$table}.{$column}] cannot be selected. It may only be used internally for joins or filters when needed.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function validateParsedSelectedColumns(object $statement, array $tableContext): void
    {
        foreach ($this->arrayValue($statement->expr ?? []) as $expression) {
            $expressionText = $this->parsedExpressionText($expression);

            if ($expressionText === '') {
                continue;
            }

            if ($this->isCountWildcard($expressionText) || $this->isCountColumnAggregate($expressionText)) {
                continue;
            }

            foreach ($this->parsedColumnReferences($expression, true) as $reference) {
                $column = $this->normalizeIdentifier((string) ($reference['column'] ?? ''));

                if ($column === '*') {
                    throw new Exception('Wildcard SELECT is not allowed. Select explicit safe columns or use COUNT(*).');
                }
            }
        }
    }

    /**
     * @return array<int, array{qualifier: string, column: string, select: bool}>
     */
    private function parsedColumnReferences(mixed $node, bool $isSelect = false): array
    {
        if (! is_object($node)) {
            return [];
        }

        $references = [];
        $table = $this->normalizeIdentifier((string) ($node->table ?? ''));
        $column = $this->normalizeIdentifier((string) ($node->column ?? ''));

        if ($column !== '') {
            $references[] = [
                'qualifier' => $table,
                'column' => $column,
                'select' => $isSelect,
            ];
        }

        foreach (get_object_vars($node) as $property => $value) {
            $childIsSelect = $isSelect || $property === 'expr';

            foreach ($this->arrayValue($value) as $child) {
                foreach ($this->parsedColumnReferences($child, $childIsSelect) as $reference) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /**
     * @param  array<string, mixed>  $tableContext
     *
     * @throws Exception
     */
    private function resolveUnqualifiedParsedColumn(string $column, array $tableContext): string
    {
        $matches = [];

        foreach ($tableContext['tables'] as $table) {
            if (in_array($column, $tableContext['columns'][$table], true)) {
                $matches[] = $table;
            }
        }

        if (empty($matches)) {
            throw new Exception('Unknown column ['.$column.']. Available safe columns: '.$this->availableSafeColumnsSummary($tableContext));
        }

        if (count($matches) > 1) {
            throw new Exception('Ambiguous column ['.$column.']. Qualify it with a table name or alias.');
        }

        return $matches[0];
    }

    private function containsNestedParsedStatement(mixed $node): bool
    {
        if (! is_object($node)) {
            return false;
        }

        $statementClass = '\\PhpMyAdmin\\SqlParser\\Statements\\Statement';

        foreach (get_object_vars($node) as $value) {
            foreach ($this->arrayValue($value) as $child) {
                if (is_object($child) && is_a($child, $statementClass)) {
                    return true;
                }

                if ($this->containsNestedParsedStatement($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        if ($value === null || is_scalar($value)) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    private function parsedExpressionText(mixed $expression): string
    {
        if (is_object($expression)) {
            foreach (['expr', 'column', 'function'] as $property) {
                if (isset($expression->{$property}) && is_scalar($expression->{$property})) {
                    return trim((string) $expression->{$property});
                }
            }
        }

        return is_scalar($expression) ? trim((string) $expression) : '';
    }

    private function normalizeParsedAlias(mixed $alias): string
    {
        if (is_object($alias)) {
            foreach (['name', 'alias', 'value'] as $property) {
                if (isset($alias->{$property}) && is_scalar($alias->{$property})) {
                    return $this->normalizeIdentifier((string) $alias->{$property});
                }
            }

            return '';
        }

        return is_scalar($alias) ? $this->normalizeIdentifier((string) $alias) : '';
    }

    /**
     * @return array{tables: array<int, string>, aliases: array<string, string>, columns: array<string, array<int, string>>, safe_columns: array<string, array<int, string>>}
     *
     * @throws Exception
     */
    private function tableContext(string $query): array
    {
        $allowedTables = $this->tables->allowedTables();

        if (empty($allowedTables)) {
            throw new Exception('No allowed tables are configured.');
        }

        preg_match_all(
            '/\b(?:from|join)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?:\s+(?:as\s+)?(?!on\b|where\b|join\b|left\b|right\b|inner\b|outer\b|full\b|cross\b|group\b|order\b|having\b|limit\b)`?([a-zA-Z_][a-zA-Z0-9_]*)`?)?/i',
            $query,
            $matches,
            PREG_SET_ORDER
        );

        $tables = [];
        $aliases = [];
        $columns = [];
        $safeColumns = [];

        foreach ($matches as $match) {
            $table = $this->normalizeIdentifier($match[1]);
            $alias = $this->normalizeIdentifier((string) ($match[2] ?? ''));

            if (! in_array($table, $allowedTables, true)) {
                throw new Exception("Table [{$table}] is not allowed.");
            }

            if (! $this->schema->hasTable($table)) {
                throw new Exception("Table [{$table}] does not exist.");
            }

            $tables[] = $table;
            $aliases[$table] = $table;

            if ($alias !== '' && ! in_array($alias, $this->reservedWords, true)) {
                $aliases[$alias] = $table;
            }

            $columns[$table] = array_map(
                fn ($column) => $this->normalizeIdentifier((string) $column),
                $this->schema->columns($table)
            );

            $safeColumns[$table] = array_values(array_filter(
                $columns[$table],
                fn ($column) => ! $this->isBlockedColumn($table, $column)
            ));
        }

        return [
            'tables' => array_values(array_unique($tables)),
            'aliases' => $aliases,
            'columns' => $columns,
            'safe_columns' => $safeColumns,
        ];
    }

    /**
     * @throws Exception
     */
    private function rejectBlockedKeywords(string $lowerQuery): void
    {
        foreach ([
            'insert',
            'update',
            'delete',
            'drop',
            'truncate',
            'alter',
            'create',
            'grant',
            'revoke',
            'replace',
            'rename',
            'union',
            'information_schema',
            'pg_catalog',
            'mysql',
        ] as $keyword) {
            if ($this->containsSqlIdentifier($lowerQuery, $keyword)) {
                throw new Exception("Restricted SQL keyword detected: {$keyword}");
            }
        }
    }

    private function isBlockedColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), $this->config->blockedColumns(), true)
            || $this->sensitiveData->isBlockedColumn($table, $column);
    }

    private function containsSqlIdentifier(string $lowerQuery, string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        if ($identifier === '') {
            return false;
        }

        return (bool) preg_match('/(?<![a-z0-9_])'.preg_quote($identifier, '/').'(?![a-z0-9_])/i', $lowerQuery);
    }

    private function selectClause(string $query): string
    {
        if (! preg_match('/^\s*select\s+(.*?)\s+from\s+/is', $query, $matches)) {
            return '';
        }

        return $matches[1];
    }

    private function nonSelectClause(string $query): string
    {
        if (! preg_match('/\sfrom\s+(.*)$/is', $query, $matches)) {
            return '';
        }

        return $matches[1];
    }

    /**
     * @return array<int, string>
     */
    private function selectedAliases(string $query): array
    {
        $aliases = [];

        foreach ($this->splitSelectExpressions($this->selectClause($query)) as $expression) {
            if (preg_match('/\s+as\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*$/i', $expression, $matches)) {
                $aliases[] = $this->normalizeIdentifier($matches[1]);
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @return array<int, string>
     */
    private function splitSelectExpressions(string $selectClause): array
    {
        $expressions = [];
        $current = '';
        $depth = 0;

        foreach (str_split($selectClause) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($character === ',' && $depth === 0) {
                $expressions[] = $current;
                $current = '';
                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $expressions[] = $current;
        }

        return $expressions;
    }

    private function isCountWildcard(string $expression): bool
    {
        return (bool) preg_match('/^\s*count\s*\(\s*\*\s*\)(\s+as\s+`?[a-zA-Z_][a-zA-Z0-9_]*`?)?\s*$/i', $expression);
    }

    private function isCountColumnAggregate(string $expression): bool
    {
        return (bool) preg_match(
            '/^\s*count\s*\(\s*(distinct\s+)?(`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\.\s*)?`?[a-zA-Z_][a-zA-Z0-9_]*`?\s*\)(\s+as\s+`?[a-zA-Z_][a-zA-Z0-9_]*`?)?\s*$/i',
            $expression
        );
    }

    private function isQualifiedReferenceOnlyCounted(string $query, string $reference): bool
    {
        foreach ($this->splitSelectExpressions($this->selectClause($query)) as $expression) {
            if (
                str_contains(strtolower($expression), strtolower($reference))
                && $this->isCountColumnAggregate($expression)
            ) {
                return true;
            }
        }

        return false;
    }

    private function withoutStringLiterals(string $query): string
    {
        return preg_replace("/'(?:''|[^'])*'|\"(?:\"\"|[^\"])*\"/s", "''", $query) ?? $query;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return strtolower(trim($identifier, " \t\n\r\0\x0B`"));
    }

    /**
     * @param  array<string, mixed>  $tableContext
     */
    private function unknownColumnMessage(string $table, string $column, array $tableContext): string
    {
        return "Unknown column [{$column}] on table [{$table}]. Available safe columns: ".implode(', ', $tableContext['safe_columns'][$table] ?? []);
    }

    /**
     * @param  array<string, mixed>  $tableContext
     */
    private function availableSafeColumnsSummary(array $tableContext): string
    {
        $parts = [];

        foreach ($tableContext['tables'] as $table) {
            $parts[] = $table.'('.implode(', ', $tableContext['safe_columns'][$table] ?? []).')';
        }

        return implode('; ', $parts);
    }
}
