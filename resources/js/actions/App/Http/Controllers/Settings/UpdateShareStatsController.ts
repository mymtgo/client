import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
const UpdateShareStatsController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateShareStatsController.url(options),
    method: 'patch',
})

UpdateShareStatsController.definition = {
    methods: ["patch"],
    url: '/settings/share-stats',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
UpdateShareStatsController.url = (options?: RouteQueryOptions) => {
    return UpdateShareStatsController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
UpdateShareStatsController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateShareStatsController.url(options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
    const UpdateShareStatsControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: UpdateShareStatsController.url({
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
        UpdateShareStatsControllerForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: UpdateShareStatsController.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    UpdateShareStatsController.form = UpdateShareStatsControllerForm
export default UpdateShareStatsController