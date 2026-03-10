import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
* @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
* @route '/settings/populate-cards'
*/
const RunPopulateCardsController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunPopulateCardsController.url(options),
    method: 'post',
})

RunPopulateCardsController.definition = {
    methods: ["post"],
    url: '/settings/populate-cards',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
* @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
* @route '/settings/populate-cards'
*/
RunPopulateCardsController.url = (options?: RouteQueryOptions) => {
    return RunPopulateCardsController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
* @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
* @route '/settings/populate-cards'
*/
RunPopulateCardsController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RunPopulateCardsController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
* @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
* @route '/settings/populate-cards'
*/
const RunPopulateCardsControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RunPopulateCardsController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
* @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
* @route '/settings/populate-cards'
*/
RunPopulateCardsControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: RunPopulateCardsController.url(options),
    method: 'post',
})

RunPopulateCardsController.form = RunPopulateCardsControllerForm

export default RunPopulateCardsController