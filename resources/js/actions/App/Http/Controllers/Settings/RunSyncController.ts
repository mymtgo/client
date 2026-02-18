import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
* @see app/Http/Controllers/Settings/RunSyncController.php:14
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
* @see app/Http/Controllers/Settings/RunSyncController.php:14
* @route '/settings/sync'
*/
RunSyncController.url = (options?: RouteQueryOptions) => {
    return RunSyncController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
* @see app/Http/Controllers/Settings/RunSyncController.php:14
* @route '/settings/sync'
*/
RunSyncController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunSyncController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
* @see app/Http/Controllers/Settings/RunSyncController.php:14
* @route '/settings/sync'
*/
const RunSyncControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RunSyncController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
* @see app/Http/Controllers/Settings/RunSyncController.php:14
* @route '/settings/sync'
*/
RunSyncControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RunSyncController.url(options),
    method: 'post',
})

RunSyncController.form = RunSyncControllerForm

export default RunSyncController