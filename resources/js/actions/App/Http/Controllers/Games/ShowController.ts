import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Games\ShowController::__invoke
 * @see app/Http/Controllers/Games/ShowController.php:15
 * @route '/games/{id}'
 */
const ShowController = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})

ShowController.definition = {
    methods: ["get","head"],
    url: '/games/{id}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Games\ShowController::__invoke
 * @see app/Http/Controllers/Games/ShowController.php:15
 * @route '/games/{id}'
 */
ShowController.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return ShowController.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Games\ShowController::__invoke
 * @see app/Http/Controllers/Games/ShowController.php:15
 * @route '/games/{id}'
 */
ShowController.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Games\ShowController::__invoke
 * @see app/Http/Controllers/Games/ShowController.php:15
 * @route '/games/{id}'
 */
ShowController.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ShowController.url(args, options),
    method: 'head',
})
export default ShowController