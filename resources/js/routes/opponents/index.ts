import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
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
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

const opponents = {
    index: Object.assign(index, index),
}

export default opponents