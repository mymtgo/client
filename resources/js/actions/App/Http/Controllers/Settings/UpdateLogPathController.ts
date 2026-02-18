import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
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

/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
* @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
* @route '/settings/log-path'
*/
const UpdateLogPathControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateLogPathController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
* @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
* @route '/settings/log-path'
*/
UpdateLogPathControllerForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateLogPathController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

UpdateLogPathController.form = UpdateLogPathControllerForm

export default UpdateLogPathController