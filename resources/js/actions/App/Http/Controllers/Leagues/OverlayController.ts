import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
* @see app/Http/Controllers/Leagues/OverlayController.php:16
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
* @see app/Http/Controllers/Leagues/OverlayController.php:16
* @route '/leagues/overlay'
*/
OverlayController.url = (options?: RouteQueryOptions) => {
    return OverlayController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
* @see app/Http/Controllers/Leagues/OverlayController.php:16
* @route '/leagues/overlay'
*/
OverlayController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OverlayController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
* @see app/Http/Controllers/Leagues/OverlayController.php:16
* @route '/leagues/overlay'
*/
OverlayController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OverlayController.url(options),
    method: 'head',
})

export default OverlayController