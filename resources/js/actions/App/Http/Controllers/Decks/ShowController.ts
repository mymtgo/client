import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
* @see app/Http/Controllers/Decks/ShowController.php:26
* @route '/decks/{deck}'
*/
const ShowController = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})

ShowController.definition = {
    methods: ["get","head"],
    url: '/decks/{deck}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
* @see app/Http/Controllers/Decks/ShowController.php:26
* @route '/decks/{deck}'
*/
ShowController.url = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return ShowController.definition.url
            .replace('{deck}', parsedArgs.deck.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
* @see app/Http/Controllers/Decks/ShowController.php:26
* @route '/decks/{deck}'
*/
ShowController.get = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
* @see app/Http/Controllers/Decks/ShowController.php:26
* @route '/decks/{deck}'
*/
ShowController.head = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ShowController.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
* @see app/Http/Controllers/Decks/ShowController.php:26
* @route '/decks/{deck}'
*/
const ShowControllerForm = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
* @see app/Http/Controllers/Decks/ShowController.php:26
* @route '/decks/{deck}'
*/
ShowControllerForm.get = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Decks\ShowController::__invoke
* @see app/Http/Controllers/Decks/ShowController.php:26
* @route '/decks/{deck}'
*/
ShowControllerForm.head = (args: { deck: string | number | { id: string | number } } | [deck: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ShowController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ShowController.form = ShowControllerForm

export default ShowController