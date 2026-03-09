import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
 * @see app/Http/Controllers/Decks/IndexController.php:12
 * @route '/decks'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/decks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
 * @see app/Http/Controllers/Decks/IndexController.php:12
 * @route '/decks'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
 * @see app/Http/Controllers/Decks/IndexController.php:12
 * @route '/decks'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
 * @see app/Http/Controllers/Decks/IndexController.php:12
 * @route '/decks'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
 * @see app/Http/Controllers/Decks/IndexController.php:12
 * @route '/decks'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
 * @see app/Http/Controllers/Decks/IndexController.php:12
 * @route '/decks'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
 * @see app/Http/Controllers/Decks/IndexController.php:12
 * @route '/decks'
 */
        indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    index.form = indexForm
/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
 * @see app/Http/Controllers/Decks/ShowController.php:26
 * @route '/decks/{deck}'
 */
export const show = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/decks/{deck}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
 * @see app/Http/Controllers/Decks/ShowController.php:26
 * @route '/decks/{deck}'
 */
show.url = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deck: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { deck: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    deck: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        deck: typeof args.deck === 'object'
                ? args.deck.id
                : args.deck,
                }

    return show.definition.url
            .replace('{deck}', parsedArgs.deck.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
 * @see app/Http/Controllers/Decks/ShowController.php:26
 * @route '/decks/{deck}'
 */
show.get = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
 * @see app/Http/Controllers/Decks/ShowController.php:26
 * @route '/decks/{deck}'
 */
show.head = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
 * @see app/Http/Controllers/Decks/ShowController.php:26
 * @route '/decks/{deck}'
 */
    const showForm = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
 * @see app/Http/Controllers/Decks/ShowController.php:26
 * @route '/decks/{deck}'
 */
        showForm.get = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
 * @see app/Http/Controllers/Decks/ShowController.php:26
 * @route '/decks/{deck}'
 */
        showForm.head = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    show.form = showForm
/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
 * @see app/Http/Controllers/Decks/PopoutController.php:15
 * @route '/decks/{deck}/popout'
 */
export const popout = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: popout.url(args, options),
    method: 'get',
})

popout.definition = {
    methods: ["get","head"],
    url: '/decks/{deck}/popout',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
 * @see app/Http/Controllers/Decks/PopoutController.php:15
 * @route '/decks/{deck}/popout'
 */
popout.url = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deck: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { deck: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    deck: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        deck: typeof args.deck === 'object'
                ? args.deck.id
                : args.deck,
                }

    return popout.definition.url
            .replace('{deck}', parsedArgs.deck.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
 * @see app/Http/Controllers/Decks/PopoutController.php:15
 * @route '/decks/{deck}/popout'
 */
popout.get = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: popout.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
 * @see app/Http/Controllers/Decks/PopoutController.php:15
 * @route '/decks/{deck}/popout'
 */
popout.head = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: popout.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
 * @see app/Http/Controllers/Decks/PopoutController.php:15
 * @route '/decks/{deck}/popout'
 */
    const popoutForm = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: popout.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
 * @see app/Http/Controllers/Decks/PopoutController.php:15
 * @route '/decks/{deck}/popout'
 */
        popoutForm.get = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: popout.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
 * @see app/Http/Controllers/Decks/PopoutController.php:15
 * @route '/decks/{deck}/popout'
 */
        popoutForm.head = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: popout.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    popout.form = popoutForm
/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
 * @see app/Http/Controllers/Decks/OpenPopoutController.php:12
 * @route '/decks/{deck}/popout'
 */
export const openPopout = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: openPopout.url(args, options),
    method: 'post',
})

openPopout.definition = {
    methods: ["post"],
    url: '/decks/{deck}/popout',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
 * @see app/Http/Controllers/Decks/OpenPopoutController.php:12
 * @route '/decks/{deck}/popout'
 */
openPopout.url = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deck: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { deck: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    deck: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        deck: typeof args.deck === 'object'
                ? args.deck.id
                : args.deck,
                }

    return openPopout.definition.url
            .replace('{deck}', parsedArgs.deck.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
 * @see app/Http/Controllers/Decks/OpenPopoutController.php:12
 * @route '/decks/{deck}/popout'
 */
openPopout.post = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: openPopout.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
 * @see app/Http/Controllers/Decks/OpenPopoutController.php:12
 * @route '/decks/{deck}/popout'
 */
    const openPopoutForm = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: openPopout.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
 * @see app/Http/Controllers/Decks/OpenPopoutController.php:12
 * @route '/decks/{deck}/popout'
 */
        openPopoutForm.post = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: openPopout.url(args, options),
            method: 'post',
        })
    
    openPopout.form = openPopoutForm
const decks = {
    index: Object.assign(index, index),
show: Object.assign(show, show),
popout: Object.assign(popout, popout),
openPopout: Object.assign(openPopout, openPopout),
}

export default decks