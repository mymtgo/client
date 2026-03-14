import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
 * @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
 * @route '/archetypes/{archetype}/download'
 */
const DownloadDecklistController = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DownloadDecklistController.url(args, options),
    method: 'post',
})

DownloadDecklistController.definition = {
    methods: ["post"],
    url: '/archetypes/{archetype}/download',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
 * @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
 * @route '/archetypes/{archetype}/download'
 */
DownloadDecklistController.url = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return DownloadDecklistController.definition.url
            .replace('{archetype}', parsedArgs.archetype.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
 * @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
 * @route '/archetypes/{archetype}/download'
 */
DownloadDecklistController.post = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DownloadDecklistController.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
 * @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
 * @route '/archetypes/{archetype}/download'
 */
    const DownloadDecklistControllerForm = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: DownloadDecklistController.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Archetypes\DownloadDecklistController::__invoke
 * @see app/Http/Controllers/Archetypes/DownloadDecklistController.php:11
 * @route '/archetypes/{archetype}/download'
 */
        DownloadDecklistControllerForm.post = (args: { archetype: number | { id: number } } | [archetype: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: DownloadDecklistController.url(args, options),
            method: 'post',
        })
    
    DownloadDecklistController.form = DownloadDecklistControllerForm
export default DownloadDecklistController