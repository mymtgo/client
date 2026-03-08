import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
const DeleteController = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: DeleteController.url(args, options),
    method: 'delete',
})

DeleteController.definition = {
    methods: ["delete"],
    url: '/matches/{id}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
DeleteController.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return DeleteController.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
DeleteController.delete = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: DeleteController.url(args, options),
    method: 'delete',
})
export default DeleteController