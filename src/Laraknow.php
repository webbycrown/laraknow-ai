<?php

namespace Webbycrown\LaraknowAi;

use Closure;
use Webbycrown\LaraknowAi\Contracts\QueryScopeResolverInterface;
use Illuminate\Support\Facades\DB;

class Laraknow
{
    /**
     * The query scope resolver closure or instance.
     *
     * @var Closure|QueryScopeResolverInterface|null
     */
    protected static $queryScopeResolver = null;

    /**
     * Configure the query scope resolver.
     *
     * @param  Closure|QueryScopeResolverInterface  $resolver
     * @return void
     */
    public static function queryScope($resolver): void
    {
        self::$queryScopeResolver = $resolver;
    }

    /**
     * Get the configured query scope resolver.
     *
     * @return Closure|QueryScopeResolverInterface|null
     */
    public static function getQueryScopeResolver()
    {
        return self::$queryScopeResolver;
    }

    /**
     * Apply the query scope to a query builder if registered.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $table
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public static function applyQueryScope($query, string $table)
    {
        $resolver = self::getQueryScopeResolver();

        if ($resolver === null && app()->bound(QueryScopeResolverInterface::class)) {
            $resolver = app(QueryScopeResolverInterface::class);
        }

        if ($resolver === null) {
            return $query;
        }

        if ($resolver instanceof QueryScopeResolverInterface) {
            return $resolver->resolve($query, $table);
        }

        if ($resolver instanceof Closure) {
            return $resolver($query, $table) ?? $query;
        }

        return $query;
    }

    /**
     * Qualify column names inside wheres array recursively.
     *
     * @param  array  $wheres
     * @param  string  $alias
     * @return array
     */
    private static function qualifyWhereColumns(array $wheres, string $alias): array
    {
        foreach ($wheres as &$where) {
            if (isset($where['column'])) {
                if (! str_contains($where['column'], '.')) {
                    $where['column'] = $alias . '.' . $where['column'];
                }
            }

            if (isset($where['query']) && $where['query'] instanceof \Illuminate\Database\Query\Builder) {
                $where['query']->wheres = self::qualifyWhereColumns($where['query']->wheres, $alias);
            }
        }

        return $wheres;
    }

    /**
     * Get the tenant scope SQL condition and bindings for a table and its alias.
     *
     * @param  string  $table
     * @param  string  $alias
     * @return array{0: string, 1: array}
     */
    public static function getTenantScopeSqlAndBindings(string $table, string $alias): array
    {
        $builder = DB::table($table);

        $builder = self::applyQueryScope($builder, $table);

        $wheres = $builder->wheres;
        if (empty($wheres)) {
            return ['', []];
        }

        $builder->wheres = self::qualifyWhereColumns($wheres, $alias);

        $grammar = $builder->getGrammar();
        $sql = $grammar->compileWheres($builder);

        if (str_starts_with(strtolower($sql), 'where ')) {
            $sql = substr($sql, 6);
        }

        return [$sql, $builder->getBindings()];
    }
}
