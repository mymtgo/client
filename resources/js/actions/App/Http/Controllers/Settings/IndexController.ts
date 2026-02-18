import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
const IndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

IndexController.definition = {
    methods: ["get","head"],
    url: '/settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

export default IndexController