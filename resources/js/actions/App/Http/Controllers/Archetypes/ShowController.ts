import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
const ShowController = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})

ShowController.definition = {
    methods: ["get","head"],
    url: '/archetypes/{archetype}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
ShowController.url = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { archetype: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { archetype: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            archetype: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        archetype: typeof args.archetype === 'object'
        ? args.archetype.id
        : args.archetype,
    }

    return ShowController.definition.url
            .replace('{archetype}', parsedArgs.archetype.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
ShowController.get = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
ShowController.head = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ShowController.url(args, options),
    method: 'head',
})

export default ShowController