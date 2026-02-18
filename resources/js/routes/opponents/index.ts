import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/opponents',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:11
* @route '/opponents'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const opponents = {
    index: Object.assign(index, index),
}

export default opponents