import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateWatcherController::__invoke
 * @see app/Http/Controllers/Settings/UpdateWatcherController.php:13
 * @route '/settings/watcher'
 */
const UpdateWatcherController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateWatcherController.url(options),
    method: 'patch',
})

UpdateWatcherController.definition = {
    methods: ["patch"],
    url: '/settings/watcher',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateWatcherController::__invoke
 * @see app/Http/Controllers/Settings/UpdateWatcherController.php:13
 * @route '/settings/watcher'
 */
UpdateWatcherController.url = (options?: RouteQueryOptions) => {
    return UpdateWatcherController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateWatcherController::__invoke
 * @see app/Http/Controllers/Settings/UpdateWatcherController.php:13
 * @route '/settings/watcher'
 */
UpdateWatcherController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateWatcherController.url(options),
    method: 'patch',
})
export default UpdateWatcherController