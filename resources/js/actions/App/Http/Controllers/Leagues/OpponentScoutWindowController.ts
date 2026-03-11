import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
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

export default OpponentScoutWindowController