import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
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

/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
* @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
* @route '/leagues/overlay/toggle'
*/
const ToggleOverlayControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ToggleOverlayController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
* @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
* @route '/leagues/overlay/toggle'
*/
ToggleOverlayControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ToggleOverlayController.url(options),
    method: 'post',
})

ToggleOverlayController.form = ToggleOverlayControllerForm

export default ToggleOverlayController