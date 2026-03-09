import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
* @see app/Http/Controllers/Decks/PopoutController.php:15
* @route '/decks/{deck}/popout'
*/
const PopoutController = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PopoutController.url(args, options),
    method: 'get',
})

PopoutController.definition = {
    methods: ["get","head"],
    url: '/decks/{deck}/popout',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
* @see app/Http/Controllers/Decks/PopoutController.php:15
* @route '/decks/{deck}/popout'
*/
PopoutController.url = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return PopoutController.definition.url
            .replace('{deck}', parsedArgs.deck.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
* @see app/Http/Controllers/Decks/PopoutController.php:15
* @route '/decks/{deck}/popout'
*/
PopoutController.get = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PopoutController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Decks\PopoutController::__invoke
* @see app/Http/Controllers/Decks/PopoutController.php:15
* @route '/decks/{deck}/popout'
*/
PopoutController.head = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PopoutController.url(args, options),
    method: 'head',
})

export default PopoutController