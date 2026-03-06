import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
* @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
* @route '/settings/data-path'
*/
const UpdateDataPathController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateDataPathController.url(options),
    method: 'patch',
})

UpdateDataPathController.definition = {
    methods: ["patch"],
    url: '/settings/data-path',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
* @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
* @route '/settings/data-path'
*/
UpdateDataPathController.url = (options?: RouteQueryOptions) => {
    return UpdateDataPathController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
* @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
* @route '/settings/data-path'
*/
UpdateDataPathController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateDataPathController.url(options),
    method: 'patch',
})

export default UpdateDataPathController