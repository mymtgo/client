import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
import cache from './cache'
import queries from './queries'
/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::openhandler
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:18
 * @route '/_debugbar/open'
 */
export const openhandler = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: openhandler.url(options),
    method: 'get',
})

openhandler.definition = {
    methods: ["get","head"],
    url: '/_debugbar/open',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::openhandler
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:18
 * @route '/_debugbar/open'
 */
openhandler.url = (options?: RouteQueryOptions) => {
    return openhandler.definition.url + queryParams(options)
}

/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::openhandler
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:18
 * @route '/_debugbar/open'
 */
openhandler.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: openhandler.url(options),
    method: 'get',
})
/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::openhandler
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:18
 * @route '/_debugbar/open'
 */
openhandler.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: openhandler.url(options),
    method: 'head',
})

    /**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::openhandler
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:18
 * @route '/_debugbar/open'
 */
    const openhandlerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: openhandler.url(options),
        method: 'get',
    })

            /**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::openhandler
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:18
 * @route '/_debugbar/open'
 */
        openhandlerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: openhandler.url(options),
            method: 'get',
        })
            /**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::openhandler
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:18
 * @route '/_debugbar/open'
 */
        openhandlerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: openhandler.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    openhandler.form = openhandlerForm
/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::clockwork
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:49
 * @route '/_debugbar/clockwork/{id}'
 */
export const clockwork = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: clockwork.url(args, options),
    method: 'get',
})

clockwork.definition = {
    methods: ["get","head"],
    url: '/_debugbar/clockwork/{id}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::clockwork
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:49
 * @route '/_debugbar/clockwork/{id}'
 */
clockwork.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { id: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    id: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        id: args.id,
                }

    return clockwork.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::clockwork
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:49
 * @route '/_debugbar/clockwork/{id}'
 */
clockwork.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: clockwork.url(args, options),
    method: 'get',
})
/**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::clockwork
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:49
 * @route '/_debugbar/clockwork/{id}'
 */
clockwork.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: clockwork.url(args, options),
    method: 'head',
})

    /**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::clockwork
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:49
 * @route '/_debugbar/clockwork/{id}'
 */
    const clockworkForm = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: clockwork.url(args, options),
        method: 'get',
    })

            /**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::clockwork
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:49
 * @route '/_debugbar/clockwork/{id}'
 */
        clockworkForm.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: clockwork.url(args, options),
            method: 'get',
        })
            /**
* @see \Fruitcake\LaravelDebugbar\Controllers\OpenHandlerController::clockwork
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/OpenHandlerController.php:49
 * @route '/_debugbar/clockwork/{id}'
 */
        clockworkForm.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: clockwork.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    clockwork.form = clockworkForm
/**
* @see \Fruitcake\LaravelDebugbar\Controllers\AssetController::assets
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/AssetController.php:16
 * @route '/_debugbar/assets'
 */
export const assets = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: assets.url(options),
    method: 'get',
})

assets.definition = {
    methods: ["get","head"],
    url: '/_debugbar/assets',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Fruitcake\LaravelDebugbar\Controllers\AssetController::assets
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/AssetController.php:16
 * @route '/_debugbar/assets'
 */
assets.url = (options?: RouteQueryOptions) => {
    return assets.definition.url + queryParams(options)
}

/**
* @see \Fruitcake\LaravelDebugbar\Controllers\AssetController::assets
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/AssetController.php:16
 * @route '/_debugbar/assets'
 */
assets.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: assets.url(options),
    method: 'get',
})
/**
* @see \Fruitcake\LaravelDebugbar\Controllers\AssetController::assets
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/AssetController.php:16
 * @route '/_debugbar/assets'
 */
assets.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: assets.url(options),
    method: 'head',
})

    /**
* @see \Fruitcake\LaravelDebugbar\Controllers\AssetController::assets
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/AssetController.php:16
 * @route '/_debugbar/assets'
 */
    const assetsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: assets.url(options),
        method: 'get',
    })

            /**
* @see \Fruitcake\LaravelDebugbar\Controllers\AssetController::assets
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/AssetController.php:16
 * @route '/_debugbar/assets'
 */
        assetsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: assets.url(options),
            method: 'get',
        })
            /**
* @see \Fruitcake\LaravelDebugbar\Controllers\AssetController::assets
 * @see vendor/fruitcake/laravel-debugbar/src/Controllers/AssetController.php:16
 * @route '/_debugbar/assets'
 */
        assetsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: assets.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    assets.form = assetsForm
const debugbar = {
    openhandler: Object.assign(openhandler, openhandler),
cache: Object.assign(cache, cache),
queries: Object.assign(queries, queries),
clockwork: Object.assign(clockwork, clockwork),
assets: Object.assign(assets, assets),
}

export default debugbar