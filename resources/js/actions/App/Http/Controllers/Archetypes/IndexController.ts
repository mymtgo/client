import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
const IndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

IndexController.definition = {
    methods: ["get","head"],
    url: '/archetypes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Archetypes\IndexController::__invoke
* @see app/Http/Controllers/Archetypes/IndexController.php:12
* @route '/archetypes'
*/
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

export default IndexController