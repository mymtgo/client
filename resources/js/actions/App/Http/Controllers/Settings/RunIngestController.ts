import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
* @see app/Http/Controllers/Settings/RunIngestController.php:14
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
* @see app/Http/Controllers/Settings/RunIngestController.php:14
* @route '/settings/ingest'
*/
RunIngestController.url = (options?: RouteQueryOptions) => {
    return RunIngestController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
* @see app/Http/Controllers/Settings/RunIngestController.php:14
* @route '/settings/ingest'
*/
RunIngestController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunIngestController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
* @see app/Http/Controllers/Settings/RunIngestController.php:14
* @route '/settings/ingest'
*/
const RunIngestControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RunIngestController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
* @see app/Http/Controllers/Settings/RunIngestController.php:14
* @route '/settings/ingest'
*/
RunIngestControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RunIngestController.url(options),
    method: 'post',
})

RunIngestController.form = RunIngestControllerForm

export default RunIngestController