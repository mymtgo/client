import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
const UpdateAnonymousStatsController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateAnonymousStatsController.url(options),
    method: 'patch',
})

UpdateAnonymousStatsController.definition = {
    methods: ["patch"],
    url: '/settings/anonymous-stats',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
UpdateAnonymousStatsController.url = (options?: RouteQueryOptions) => {
    return UpdateAnonymousStatsController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
UpdateAnonymousStatsController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateAnonymousStatsController.url(options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
    const UpdateAnonymousStatsControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: UpdateAnonymousStatsController.url({
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
        UpdateAnonymousStatsControllerForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: UpdateAnonymousStatsController.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    UpdateAnonymousStatsController.form = UpdateAnonymousStatsControllerForm
export default UpdateAnonymousStatsController