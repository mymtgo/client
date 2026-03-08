import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
 * @see app/Http/Controllers/Settings/RunIngestController.php:13
 * @route '/settings/ingest'
 */
const RunIngestController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunIngestController.url(options),
    method: 'post',
})

RunIngestController.definition = {
    methods: ["post"],
    url: '/settings/ingest',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
 * @see app/Http/Controllers/Settings/RunIngestController.php:13
 * @route '/settings/ingest'
 */
RunIngestController.url = (options?: RouteQueryOptions) => {
    return RunIngestController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
 * @see app/Http/Controllers/Settings/RunIngestController.php:13
 * @route '/settings/ingest'
 */
RunIngestController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunIngestController.url(options),
    method: 'post',
})
export default RunIngestController