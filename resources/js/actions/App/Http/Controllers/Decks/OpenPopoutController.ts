import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
* @see app/Http/Controllers/Decks/OpenPopoutController.php:12
* @route '/decks/{deck}/popout'
*/
const OpenPopoutController = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: OpenPopoutController.url(args, options),
    method: 'post',
})

OpenPopoutController.definition = {
    methods: ["post"],
    url: '/decks/{deck}/popout',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
* @see app/Http/Controllers/Decks/OpenPopoutController.php:12
* @route '/decks/{deck}/popout'
*/
OpenPopoutController.url = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return OpenPopoutController.definition.url
            .replace('{deck}', parsedArgs.deck.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
* @see app/Http/Controllers/Decks/OpenPopoutController.php:12
* @route '/decks/{deck}/popout'
*/
OpenPopoutController.post = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: OpenPopoutController.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
* @see app/Http/Controllers/Decks/OpenPopoutController.php:12
* @route '/decks/{deck}/popout'
*/
const OpenPopoutControllerForm = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: OpenPopoutController.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Decks\OpenPopoutController::__invoke
* @see app/Http/Controllers/Decks/OpenPopoutController.php:12
* @route '/decks/{deck}/popout'
*/
OpenPopoutControllerForm.post = (args: { deck: number | { id: number } } | [deck: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: OpenPopoutController.url(args, options),
    method: 'post',
})

OpenPopoutController.form = OpenPopoutControllerForm

export default OpenPopoutController