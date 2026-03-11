import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
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

export default BrowseFolderController