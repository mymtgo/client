import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Leagues\OpponentScoutWindowController::__invoke
* @see app/Http/Controllers/Leagues/OpponentScoutWindowController.php:13
* @route '/leagues/opponent-scout'
*/
const OpponentScoutWindowController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OpponentScoutWindowController.url(options),
    method: 'get',
})

OpponentScoutWindowController.definition = {
    methods: ["get","head"],
    url: '/leagues/opponent-scout',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Leagues\OpponentScoutWindowController::__invoke
* @see app/Http/Controllers/Leagues/OpponentScoutWindowController.php:13
* @route '/leagues/opponent-scout'
*/
OpponentScoutWindowController.url = (options?: RouteQueryOptions) => {
    return OpponentScoutWindowController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Leagues\OpponentScoutWindowController::__invoke
* @see app/Http/Controllers/Leagues/OpponentScoutWindowController.php:13
* @route '/leagues/opponent-scout'
*/
OpponentScoutWindowController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OpponentScoutWindowController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\OpponentScoutWindowController::__invoke
* @see app/Http/Controllers/Leagues/OpponentScoutWindowController.php:13
* @route '/leagues/opponent-scout'
*/
OpponentScoutWindowController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OpponentScoutWindowController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Leagues\OpponentScoutWindowController::__invoke
* @see app/Http/Controllers/Leagues/OpponentScoutWindowController.php:13
* @route '/leagues/opponent-scout'
*/
const OpponentScoutWindowControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: OpponentScoutWindowController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\OpponentScoutWindowController::__invoke
* @see app/Http/Controllers/Leagues/OpponentScoutWindowController.php:13
* @route '/leagues/opponent-scout'
*/
OpponentScoutWindowControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: OpponentScoutWindowController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Leagues\OpponentScoutWindowController::__invoke
* @see app/Http/Controllers/Leagues/OpponentScoutWindowController.php:13
* @route '/leagues/opponent-scout'
*/
OpponentScoutWindowControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: OpponentScoutWindowController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

OpponentScoutWindowController.form = OpponentScoutWindowControllerForm

export default OpponentScoutWindowController