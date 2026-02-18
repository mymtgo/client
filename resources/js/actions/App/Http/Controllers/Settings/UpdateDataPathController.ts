import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
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

/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
* @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
* @route '/settings/data-path'
*/
const UpdateDataPathControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateDataPathController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
* @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
* @route '/settings/data-path'
*/
UpdateDataPathControllerForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateDataPathController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

UpdateDataPathController.form = UpdateDataPathControllerForm

export default UpdateDataPathController