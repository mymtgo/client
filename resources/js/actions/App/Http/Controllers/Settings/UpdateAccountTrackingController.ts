import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateAccountTrackingController::__invoke
* @see app/Http/Controllers/Settings/UpdateAccountTrackingController.php:12
* @route '/settings/account-tracking'
*/
const UpdateAccountTrackingController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateAccountTrackingController.url(options),
    method: 'patch',
})

UpdateAccountTrackingController.definition = {
    methods: ["patch"],
    url: '/settings/account-tracking',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateAccountTrackingController::__invoke
* @see app/Http/Controllers/Settings/UpdateAccountTrackingController.php:12
* @route '/settings/account-tracking'
*/
UpdateAccountTrackingController.url = (options?: RouteQueryOptions) => {
    return UpdateAccountTrackingController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateAccountTrackingController::__invoke
* @see app/Http/Controllers/Settings/UpdateAccountTrackingController.php:12
* @route '/settings/account-tracking'
*/
UpdateAccountTrackingController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateAccountTrackingController.url(options),
    method: 'patch',
})

export default UpdateAccountTrackingController