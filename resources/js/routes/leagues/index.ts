import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import overlayE7e003 from './overlay'
/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:16
* @route '/leagues'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/leagues',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:16
* @route '/leagues'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:16
* @route '/leagues'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\IndexController::__invoke
* @see app/Http/Controllers/Leagues/IndexController.php:16
* @route '/leagues'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
* @see app/Http/Controllers/Leagues/OverlayController.php:16
* @route '/leagues/overlay'
*/
export const overlay = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: overlay.url(options),
    method: 'get',
})

overlay.definition = {
    methods: ["get","head"],
    url: '/leagues/overlay',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
* @see app/Http/Controllers/Leagues/OverlayController.php:16
* @route '/leagues/overlay'
*/
overlay.url = (options?: RouteQueryOptions) => {
    return overlay.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
* @see app/Http/Controllers/Leagues/OverlayController.php:16
* @route '/leagues/overlay'
*/
overlay.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: overlay.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\OverlayController::__invoke
* @see app/Http/Controllers/Leagues/OverlayController.php:16
* @route '/leagues/overlay'
*/
overlay.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: overlay.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
export const abandon = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: abandon.url(args, options),
    method: 'delete',
})

abandon.definition = {
    methods: ["delete"],
    url: '/leagues/{league}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
abandon.url = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { league: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { league: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            league: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        league: typeof args.league === 'object'
        ? args.league.id
        : args.league,
    }

    return abandon.definition.url
            .replace('{league}', parsedArgs.league.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
abandon.delete = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: abandon.url(args, options),
    method: 'delete',
})

const leagues = {
    index: Object.assign(index, index),
    overlay: Object.assign(overlay, overlayE7e003),
    abandon: Object.assign(abandon, abandon),
}

export default leagues