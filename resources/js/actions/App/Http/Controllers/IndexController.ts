import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:11
 * @route '/'
 */
const IndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

IndexController.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:11
 * @route '/'
 */
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:11
 * @route '/'
 */
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:11
 * @route '/'
 */
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})
export default IndexController