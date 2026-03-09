import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\OpenOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OpenOverlayController.php:11
 * @route '/leagues/overlay/open'
 */
const OpenOverlayController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: OpenOverlayController.url(options),
    method: 'post',
})

OpenOverlayController.definition = {
    methods: ["post"],
    url: '/leagues/overlay/open',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Leagues\OpenOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OpenOverlayController.php:11
 * @route '/leagues/overlay/open'
 */
OpenOverlayController.url = (options?: RouteQueryOptions) => {
    return OpenOverlayController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\OpenOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OpenOverlayController.php:11
 * @route '/leagues/overlay/open'
 */
OpenOverlayController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: OpenOverlayController.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Leagues\OpenOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OpenOverlayController.php:11
 * @route '/leagues/overlay/open'
 */
    const OpenOverlayControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: OpenOverlayController.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Leagues\OpenOverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OpenOverlayController.php:11
 * @route '/leagues/overlay/open'
 */
        OpenOverlayControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: OpenOverlayController.url(options),
            method: 'post',
        })
    
    OpenOverlayController.form = OpenOverlayControllerForm
export default OpenOverlayController