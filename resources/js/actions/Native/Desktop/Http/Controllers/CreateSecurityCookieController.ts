import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
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

export default CreateSecurityCookieController