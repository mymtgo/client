import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\RunSubmitMatchesController::__invoke
 * @see app/Http/Controllers/Settings/RunSubmitMatchesController.php:13
 * @route '/settings/submit-matches'
 */
const RunSubmitMatchesController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunSubmitMatchesController.url(options),
    method: 'post',
})

RunSubmitMatchesController.definition = {
    methods: ["post"],
    url: '/settings/submit-matches',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunSubmitMatchesController::__invoke
 * @see app/Http/Controllers/Settings/RunSubmitMatchesController.php:13
 * @route '/settings/submit-matches'
 */
RunSubmitMatchesController.url = (options?: RouteQueryOptions) => {
    return RunSubmitMatchesController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunSubmitMatchesController::__invoke
 * @see app/Http/Controllers/Settings/RunSubmitMatchesController.php:13
 * @route '/settings/submit-matches'
 */
RunSubmitMatchesController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunSubmitMatchesController.url(options),
    method: 'post',
})
export default RunSubmitMatchesController