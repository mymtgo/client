import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
const IndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

IndexController.definition = {
    methods: ["get","head"],
    url: '/leagues',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:10
* @route '/leagues'
*/
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

export default IndexController