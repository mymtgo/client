import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
 * @route '/leagues/overlay/toggle'
 */
export const toggle = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggle.url(options),
    method: 'post',
})

toggle.definition = {
    methods: ["post"],
    url: '/leagues/overlay/toggle',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
 * @route '/leagues/overlay/toggle'
 */
toggle.url = (options?: RouteQueryOptions) => {
    return toggle.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
 * @route '/leagues/overlay/toggle'
 */
toggle.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: toggle.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
 * @route '/leagues/overlay/toggle'
 */
    const toggleForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: toggle.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Leagues\ToggleOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/ToggleOverlayController.php:12
 * @route '/leagues/overlay/toggle'
 */
        toggleForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: toggle.url(options),
            method: 'post',
        })
    
    toggle.form = toggleForm
const overlay = {
    toggle: Object.assign(toggle, toggle),
}

export default overlay