import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
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
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
const IndexControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
IndexControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Opponents\IndexController::__invoke
* @see app/Http/Controllers/Opponents/IndexController.php:13
* @route '/opponents'
*/
IndexControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IndexController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

IndexController.form = IndexControllerForm

export default IndexController