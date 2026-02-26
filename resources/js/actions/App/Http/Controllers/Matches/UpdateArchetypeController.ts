import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
const UpdateArchetypeController = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateArchetypeController.url(args, options),
    method: 'patch',
})

UpdateArchetypeController.definition = {
    methods: ["patch"],
    url: '/matches/{id}/archetype',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
UpdateArchetypeController.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return UpdateArchetypeController.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
UpdateArchetypeController.patch = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: UpdateArchetypeController.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
    const UpdateArchetypeControllerForm = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: UpdateArchetypeController.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'PATCH',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
        UpdateArchetypeControllerForm.patch = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: UpdateArchetypeController.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    UpdateArchetypeController.form = UpdateArchetypeControllerForm
export default UpdateArchetypeController