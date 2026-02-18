import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
* @see app/Http/Controllers/Matches/ShowController.php:15
* @route '/matches/{id}'
*/
const ShowController = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})

ShowController.definition = {
    methods: ["get","head"],
    url: '/matches/{id}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
* @see app/Http/Controllers/Matches/ShowController.php:15
* @route '/matches/{id}'
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
* @see \App\Http\Controllers\Matches\ShowController::__invoke
* @see app/Http/Controllers/Matches/ShowController.php:15
* @route '/matches/{id}'
*/
ShowController.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
* @see app/Http/Controllers/Matches/ShowController.php:15
* @route '/matches/{id}'
*/
ShowController.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ShowController.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
* @see app/Http/Controllers/Matches/ShowController.php:15
* @route '/matches/{id}'
*/
const ShowControllerForm = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
* @see app/Http/Controllers/Matches/ShowController.php:15
* @route '/matches/{id}'
*/
ShowControllerForm.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
* @see app/Http/Controllers/Matches/ShowController.php:15
* @route '/matches/{id}'
*/
ShowControllerForm.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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