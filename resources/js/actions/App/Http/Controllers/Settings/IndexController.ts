import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:16
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
* @see app/Http/Controllers/Settings/IndexController.php:16
* @route '/settings'
*/
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:16
* @route '/settings'
*/
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:16
* @route '/settings'
*/
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:16
* @route '/settings'
*/
const IndexControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:16
* @route '/settings'
*/
IndexControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
* @see app/Http/Controllers/Settings/IndexController.php:16
* @route '/settings'
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