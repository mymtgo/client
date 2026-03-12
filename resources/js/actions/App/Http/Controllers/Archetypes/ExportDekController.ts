import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Archetypes\ExportDekController::__invoke
* @see app/Http/Controllers/Archetypes/ExportDekController.php:14
* @route '/archetypes/{archetype}/export'
*/
const ExportDekController = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ExportDekController.url(args, options),
    method: 'post',
})

ExportDekController.definition = {
    methods: ["post"],
    url: '/archetypes/{archetype}/export',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Archetypes\ExportDekController::__invoke
* @see app/Http/Controllers/Archetypes/ExportDekController.php:14
* @route '/archetypes/{archetype}/export'
*/
ExportDekController.url = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return ExportDekController.definition.url
            .replace('{archetype}', parsedArgs.archetype.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\ExportDekController::__invoke
* @see app/Http/Controllers/Archetypes/ExportDekController.php:14
* @route '/archetypes/{archetype}/export'
*/
ExportDekController.post = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ExportDekController.url(args, options),
    method: 'post',
})

export default ExportDekController