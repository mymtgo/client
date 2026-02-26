import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
 * @see app/Http/Controllers/Matches/ShowController.php:19
 * @route '/matches/{id}'
 */
export const show = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/matches/{id}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
 * @see app/Http/Controllers/Matches/ShowController.php:19
 * @route '/matches/{id}'
 */
show.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
 * @see app/Http/Controllers/Matches/ShowController.php:19
 * @route '/matches/{id}'
 */
show.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
 * @see app/Http/Controllers/Matches/ShowController.php:19
 * @route '/matches/{id}'
 */
show.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
 * @see app/Http/Controllers/Matches/ShowController.php:19
 * @route '/matches/{id}'
 */
    const showForm = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
 * @see app/Http/Controllers/Matches/ShowController.php:19
 * @route '/matches/{id}'
 */
        showForm.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Matches\ShowController::__invoke
 * @see app/Http/Controllers/Matches/ShowController.php:19
 * @route '/matches/{id}'
 */
        showForm.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    show.form = showForm
/**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
export const updateArchetype = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateArchetype.url(args, options),
    method: 'patch',
})

updateArchetype.definition = {
    methods: ["patch"],
    url: '/matches/{id}/archetype',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
updateArchetype.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return updateArchetype.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
updateArchetype.patch = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateArchetype.url(args, options),
    method: 'patch',
})

    /**
* @see \App\Http\Controllers\Matches\UpdateArchetypeController::__invoke
 * @see app/Http/Controllers/Matches/UpdateArchetypeController.php:12
 * @route '/matches/{id}/archetype'
 */
    const updateArchetypeForm = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: updateArchetype.url(args, {
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
        updateArchetypeForm.patch = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: updateArchetype.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'PATCH',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    updateArchetype.form = updateArchetypeForm
/**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
export const deleteMethod = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMethod.url(args, options),
    method: 'delete',
})

deleteMethod.definition = {
    methods: ["delete"],
    url: '/matches/{id}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
deleteMethod.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return deleteMethod.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
deleteMethod.delete = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMethod.url(args, options),
    method: 'delete',
})

    /**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
    const deleteMethodForm = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: deleteMethod.url(args, {
                    [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                        _method: 'DELETE',
                        ...(options?.query ?? options?.mergeQuery ?? {}),
                    }
                }),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Matches\DeleteController::__invoke
 * @see app/Http/Controllers/Matches/DeleteController.php:11
 * @route '/matches/{id}'
 */
        deleteMethodForm.delete = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: deleteMethod.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'DELETE',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'post',
        })
    
    deleteMethod.form = deleteMethodForm
const matches = {
    show: Object.assign(show, show),
updateArchetype: Object.assign(updateArchetype, updateArchetype),
delete: Object.assign(deleteMethod, deleteMethod),
}

export default matches