import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
* @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
* @route '/settings/hide-phantom'
*/
const UpdateHidePhantomController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateHidePhantomController.url(options),
    method: 'patch',
})

UpdateHidePhantomController.definition = {
    methods: ["patch"],
    url: '/settings/hide-phantom',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
* @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
* @route '/settings/hide-phantom'
*/
UpdateHidePhantomController.url = (options?: RouteQueryOptions) => {
    return UpdateHidePhantomController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
* @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
* @route '/settings/hide-phantom'
*/
UpdateHidePhantomController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateHidePhantomController.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
* @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
* @route '/settings/hide-phantom'
*/
const UpdateHidePhantomControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateHidePhantomController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
* @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
* @route '/settings/hide-phantom'
*/
UpdateHidePhantomControllerForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: UpdateHidePhantomController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

UpdateHidePhantomController.form = UpdateHidePhantomControllerForm

export default UpdateHidePhantomController