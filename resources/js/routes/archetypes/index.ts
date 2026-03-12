import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/archetypes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
export const show = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/archetypes/{archetype}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
show.url = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{archetype}', parsedArgs.archetype.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
show.get = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Archetypes\ShowController::__invoke
* @see app/Http/Controllers/Archetypes/ShowController.php:17
* @route '/archetypes/{archetype}'
*/
show.head = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
* @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
* @route '/archetypes/{archetype}/download'
*/
export const download = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: download.url(args, options),
    method: 'post',
})

download.definition = {
    methods: ["post"],
    url: '/archetypes/{archetype}/download',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
* @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
* @route '/archetypes/{archetype}/download'
*/
download.url = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return download.definition.url
            .replace('{archetype}', parsedArgs.archetype.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
* @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
* @route '/archetypes/{archetype}/download'
*/
download.post = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: download.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Archetypes\ExportDekController::__invoke
* @see app/Http/Controllers/Archetypes/ExportDekController.php:14
* @route '/archetypes/{archetype}/export'
*/
export const exportMethod = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: exportMethod.url(args, options),
    method: 'post',
})

exportMethod.definition = {
    methods: ["post"],
    url: '/archetypes/{archetype}/export',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Archetypes\ExportDekController::__invoke
* @see app/Http/Controllers/Archetypes/ExportDekController.php:14
* @route '/archetypes/{archetype}/export'
*/
exportMethod.url = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return exportMethod.definition.url
            .replace('{archetype}', parsedArgs.archetype.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\ExportDekController::__invoke
* @see app/Http/Controllers/Archetypes/ExportDekController.php:14
* @route '/archetypes/{archetype}/export'
*/
exportMethod.post = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: exportMethod.url(args, options),
    method: 'post',
})

const archetypes = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
    download: Object.assign(download, download),
    export: Object.assign(exportMethod, exportMethod),
}

export default archetypes