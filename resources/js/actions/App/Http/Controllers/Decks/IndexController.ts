import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
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

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
* @see app/Http/Controllers/Decks/IndexController.php:10
* @route '/decks'
*/
const IndexControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
* @see app/Http/Controllers/Decks/IndexController.php:10
* @route '/decks'
*/
IndexControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: IndexController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Decks\IndexController::__invoke
* @see app/Http/Controllers/Decks/IndexController.php:10
* @route '/decks'
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