import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
* @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
* @route '/leagues/overlay/toggle'
*/
const ToggleOverlayController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ToggleOverlayController.url(options),
    method: 'post',
})

ToggleOverlayController.definition = {
    methods: ["post"],
    url: '/leagues/overlay/toggle',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
* @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
* @route '/leagues/overlay/toggle'
*/
ToggleOverlayController.url = (options?: RouteQueryOptions) => {
    return ToggleOverlayController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
* @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
* @route '/leagues/overlay/toggle'
*/
ToggleOverlayController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ToggleOverlayController.url(options),
    method: 'post',
})

export default ToggleOverlayController