import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
* @see app/Http/Controllers/Decks/IndexController.php:10
* @route '/decks'
*/
const IndexController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

IndexController.definition = {
    methods: ["get","head"],
    url: '/decks',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
* @see app/Http/Controllers/Decks/IndexController.php:10
* @route '/decks'
*/
IndexController.url = (options?: RouteQueryOptions) => {
    return IndexController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
* @see app/Http/Controllers/Decks/IndexController.php:10
* @route '/decks'
*/
IndexController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
* @see app/Http/Controllers/Decks/IndexController.php:10
* @route '/decks'
*/
IndexController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: IndexController.url(options),
    method: 'head',
})

export default IndexController