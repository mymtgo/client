import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
const AbandonController = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: AbandonController.url(args, options),
    method: 'delete',
})

AbandonController.definition = {
    methods: ["delete"],
    url: '/leagues/{league}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
AbandonController.url = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return AbandonController.definition.url
            .replace('{league}', parsedArgs.league.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
AbandonController.delete = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: AbandonController.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
const AbandonControllerForm = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: AbandonController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Leagues\AbandonController::__invoke
* @see app/Http/Controllers/Leagues/AbandonController.php:11
* @route '/leagues/{league}'
*/
AbandonControllerForm.delete = (args: { league: number | { id: number } } | [league: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: AbandonController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

AbandonController.form = AbandonControllerForm

export default AbandonController