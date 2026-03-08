import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateOverlaySettingsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateOverlaySettingsController.php:23
 * @route '/settings/overlay'
 */
const UpdateOverlaySettingsController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: UpdateOverlaySettingsController.url(options),
    method: 'post',
})

UpdateOverlaySettingsController.definition = {
    methods: ["post"],
    url: '/settings/overlay',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\UpdateOverlaySettingsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateOverlaySettingsController.php:23
 * @route '/settings/overlay'
 */
UpdateOverlaySettingsController.url = (options?: RouteQueryOptions) => {
    return UpdateOverlaySettingsController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateOverlaySettingsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateOverlaySettingsController.php:23
 * @route '/settings/overlay'
 */
UpdateOverlaySettingsController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: UpdateOverlaySettingsController.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Settings\UpdateOverlaySettingsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateOverlaySettingsController.php:23
 * @route '/settings/overlay'
 */
    const UpdateOverlaySettingsControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: UpdateOverlaySettingsController.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\UpdateOverlaySettingsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateOverlaySettingsController.php:23
 * @route '/settings/overlay'
 */
        UpdateOverlaySettingsControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: UpdateOverlaySettingsController.url(options),
            method: 'post',
        })
    
    UpdateOverlaySettingsController.form = UpdateOverlaySettingsControllerForm
export default UpdateOverlaySettingsController