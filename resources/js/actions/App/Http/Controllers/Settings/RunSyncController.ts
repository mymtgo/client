import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
 * @see app/Http/Controllers/Settings/RunSyncController.php:13
 * @route '/settings/sync'
 */
const RunSyncController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunSyncController.url(options),
    method: 'post',
})

RunSyncController.definition = {
    methods: ["post"],
    url: '/settings/sync',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
 * @see app/Http/Controllers/Settings/RunSyncController.php:13
 * @route '/settings/sync'
 */
RunSyncController.url = (options?: RouteQueryOptions) => {
    return RunSyncController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
 * @see app/Http/Controllers/Settings/RunSyncController.php:13
 * @route '/settings/sync'
 */
RunSyncController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunSyncController.url(options),
    method: 'post',
})
export default RunSyncController