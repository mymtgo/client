import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:11
* @route '/settings'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const settings = {
    index: Object.assign(index, index),
}

export default settings