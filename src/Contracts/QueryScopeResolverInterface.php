<?php

namespace Webbycrown\LaraknowAi\Contracts;

interface QueryScopeResolverInterface
{
    /**
     * Apply tenant scoping to the database query builder.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $table
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public function resolve($query, string $table);
}
