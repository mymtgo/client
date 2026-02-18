import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
const IndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

IndexController.definition = {
    methods: ["get","head"],
    url: '/opponents',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

export default IndexController