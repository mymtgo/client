import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/leagues',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const leagues = {
    index: Object.assign(index, index),
}

export default leagues