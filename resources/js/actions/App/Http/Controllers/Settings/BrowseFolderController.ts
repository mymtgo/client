import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\BrowseFolderController::__invoke
* @see app/Http/Controllers/Settings/BrowseFolderController.php:12
* @route '/settings/browse-folder'
*/
const BrowseFolderController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: BrowseFolderController.url(options),
    method: 'get',
})

BrowseFolderController.definition = {
    methods: ["get","head"],
    url: '/settings/browse-folder',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\BrowseFolderController::__invoke
* @see app/Http/Controllers/Settings/BrowseFolderController.php:12
* @route '/settings/browse-folder'
*/
BrowseFolderController.url = (options?: RouteQueryOptions) => {
    return BrowseFolderController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BrowseFolderController::__invoke
* @see app/Http/Controllers/Settings/BrowseFolderController.php:12
* @route '/settings/browse-folder'
*/
BrowseFolderController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: BrowseFolderController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\BrowseFolderController::__invoke
* @see app/Http/Controllers/Settings/BrowseFolderController.php:12
* @route '/settings/browse-folder'
*/
BrowseFolderController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: BrowseFolderController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\BrowseFolderController::__invoke
* @see app/Http/Controllers/Settings/BrowseFolderController.php:12
* @route '/settings/browse-folder'
*/
const BrowseFolderControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: BrowseFolderController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\BrowseFolderController::__invoke
* @see app/Http/Controllers/Settings/BrowseFolderController.php:12
* @route '/settings/browse-folder'
*/
BrowseFolderControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: BrowseFolderController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\BrowseFolderController::__invoke
* @see app/Http/Controllers/Settings/BrowseFolderController.php:12
* @route '/settings/browse-folder'
*/
BrowseFolderControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: BrowseFolderController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

BrowseFolderController.form = BrowseFolderControllerForm

export default BrowseFolderController