import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OverlayController.php:15
 * @route '/leagues/overlay'
 */
const OverlayController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OverlayController.url(options),
    method: 'get',
})

OverlayController.definition = {
    methods: ["get","head"],
    url: '/leagues/overlay',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OverlayController.php:15
 * @route '/leagues/overlay'
 */
OverlayController.url = (options?: RouteQueryOptions) => {
    return OverlayController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OverlayController.php:15
 * @route '/leagues/overlay'
 */
OverlayController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OverlayController.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OverlayController.php:15
 * @route '/leagues/overlay'
 */
OverlayController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OverlayController.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OverlayController.php:15
 * @route '/leagues/overlay'
 */
    const OverlayControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: OverlayController.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OverlayController.php:15
 * @route '/leagues/overlay'
 */
        OverlayControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OverlayController.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
 * @see app/Http/Controllers/Leagues/OverlayController.php:15
 * @route '/leagues/overlay'
 */
        OverlayControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OverlayController.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    OverlayController.form = OverlayControllerForm
export default OverlayController