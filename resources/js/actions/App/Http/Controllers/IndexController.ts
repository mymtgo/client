import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:16
 * @route '/'
 */
const IndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

IndexController.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:16
 * @route '/'
 */
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:16
 * @route '/'
 */
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:16
 * @route '/'
 */
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:16
 * @route '/'
 */
    const IndexControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: IndexController.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:16
 * @route '/'
 */
        IndexControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: IndexController.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\IndexController::__invoke
 * @see app/Http/Controllers/IndexController.php:16
 * @route '/'
 */
        IndexControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: IndexController.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    IndexController.form = IndexControllerForm
export default IndexController