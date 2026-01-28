import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Native\Desktop\Http\Controllers\DispatchEventFromAppController::__invoke
* @see vendor/nativephp/desktop/src/Http/Controllers/DispatchEventFromAppController.php:9
* @route '/_native/api/events'
*/
const DispatchEventFromAppController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DispatchEventFromAppController.url(options),
    method: 'post',
})

DispatchEventFromAppController.definition = {
    methods: ["post"],
    url: '/_native/api/events',
} satisfies RouteDefinition<["post"]>

/**
* @see \Native\Desktop\Http\Controllers\DispatchEventFromAppController::__invoke
* @see vendor/nativephp/desktop/src/Http/Controllers/DispatchEventFromAppController.php:9
* @route '/_native/api/events'
*/
DispatchEventFromAppController.url = (options?: RouteQueryOptions) => {
    return DispatchEventFromAppController.definition.url + queryParams(options)
}

/**
* @see \Native\Desktop\Http\Controllers\DispatchEventFromAppController::__invoke
* @see vendor/nativephp/desktop/src/Http/Controllers/DispatchEventFromAppController.php:9
* @route '/_native/api/events'
*/
DispatchEventFromAppController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DispatchEventFromAppController.url(options),
    method: 'post',
})

export default DispatchEventFromAppController