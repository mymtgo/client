import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Native\Laravel\Http\Controllers\NativeAppBootedController::__invoke
* @see vendor/nativephp/laravel/src/Http/Controllers/NativeAppBootedController.php:10
* @route '/_native/api/booted'
*/
const NativeAppBootedController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: NativeAppBootedController.url(options),
    method: 'post',
})

NativeAppBootedController.definition = {
    methods: ["post"],
    url: '/_native/api/booted',
} satisfies RouteDefinition<["post"]>

/**
* @see \Native\Laravel\Http\Controllers\NativeAppBootedController::__invoke
* @see vendor/nativephp/laravel/src/Http/Controllers/NativeAppBootedController.php:10
* @route '/_native/api/booted'
*/
NativeAppBootedController.url = (options?: RouteQueryOptions) => {
    return NativeAppBootedController.definition.url + queryParams(options)
}

/**
* @see \Native\Laravel\Http\Controllers\NativeAppBootedController::__invoke
* @see vendor/nativephp/laravel/src/Http/Controllers/NativeAppBootedController.php:10
* @route '/_native/api/booted'
*/
NativeAppBootedController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: NativeAppBootedController.url(options),
    method: 'post',
})

export default NativeAppBootedController