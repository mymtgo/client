import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
 * @route '/settings/log-path'
 */
const UpdateLogPathController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateLogPathController.url(options),
    method: 'patch',
})

UpdateLogPathController.definition = {
    methods: ["patch"],
    url: '/settings/log-path',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
 * @route '/settings/log-path'
 */
UpdateLogPathController.url = (options?: RouteQueryOptions) => {
    return UpdateLogPathController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
 * @route '/settings/log-path'
 */
UpdateLogPathController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateLogPathController.url(options),
    method: 'patch',
})
export default UpdateLogPathController