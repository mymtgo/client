import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \Native\Desktop\Http\Controllers\CreateSecurityCookieController::__invoke
 * @see vendor/nativephp/desktop/src/Http/Controllers/CreateSecurityCookieController.php:9
 * @route '/_native/api/cookie'
 */
const CreateSecurityCookieController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CreateSecurityCookieController.url(options),
    method: 'get',
})

CreateSecurityCookieController.definition = {
    methods: ["get","head"],
    url: '/_native/api/cookie',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Native\Desktop\Http\Controllers\CreateSecurityCookieController::__invoke
 * @see vendor/nativephp/desktop/src/Http/Controllers/CreateSecurityCookieController.php:9
 * @route '/_native/api/cookie'
 */
CreateSecurityCookieController.url = (options?: RouteQueryOptions) => {
    return CreateSecurityCookieController.definition.url + queryParams(options)
}

/**
* @see \Native\Desktop\Http\Controllers\CreateSecurityCookieController::__invoke
 * @see vendor/nativephp/desktop/src/Http/Controllers/CreateSecurityCookieController.php:9
 * @route '/_native/api/cookie'
 */
CreateSecurityCookieController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: CreateSecurityCookieController.url(options),
    method: 'get',
})
/**
* @see \Native\Desktop\Http\Controllers\CreateSecurityCookieController::__invoke
 * @see vendor/nativephp/desktop/src/Http/Controllers/CreateSecurityCookieController.php:9
 * @route '/_native/api/cookie'
 */
CreateSecurityCookieController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: CreateSecurityCookieController.url(options),
    method: 'head',
})

    /**
* @see \Native\Desktop\Http\Controllers\CreateSecurityCookieController::__invoke
 * @see vendor/nativephp/desktop/src/Http/Controllers/CreateSecurityCookieController.php:9
 * @route '/_native/api/cookie'
 */
    const CreateSecurityCookieControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: CreateSecurityCookieController.url(options),
        method: 'get',
    })

            /**
* @see \Native\Desktop\Http\Controllers\CreateSecurityCookieController::__invoke
 * @see vendor/nativephp/desktop/src/Http/Controllers/CreateSecurityCookieController.php:9
 * @route '/_native/api/cookie'
 */
        CreateSecurityCookieControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: CreateSecurityCookieController.url(options),
            method: 'get',
        })
            /**
* @see \Native\Desktop\Http\Controllers\CreateSecurityCookieController::__invoke
 * @see vendor/nativephp/desktop/src/Http/Controllers/CreateSecurityCookieController.php:9
 * @route '/_native/api/cookie'
 */
        CreateSecurityCookieControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: CreateSecurityCookieController.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    CreateSecurityCookieController.form = CreateSecurityCookieControllerForm
export default CreateSecurityCookieController