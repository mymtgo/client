import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\SwitchAccountController::__invoke
* @see app/Http/Controllers/Settings/SwitchAccountController.php:12
* @route '/settings/switch-account'
*/
const SwitchAccountController = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: SwitchAccountController.url(options),
    method: 'patch',
})

SwitchAccountController.definition = {
    methods: ["patch"],
    url: '/settings/switch-account',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\SwitchAccountController::__invoke
* @see app/Http/Controllers/Settings/SwitchAccountController.php:12
* @route '/settings/switch-account'
*/
SwitchAccountController.url = (options?: RouteQueryOptions) => {
    return SwitchAccountController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\SwitchAccountController::__invoke
* @see app/Http/Controllers/Settings/SwitchAccountController.php:12
* @route '/settings/switch-account'
*/
SwitchAccountController.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: SwitchAccountController.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\SwitchAccountController::__invoke
* @see app/Http/Controllers/Settings/SwitchAccountController.php:12
* @route '/settings/switch-account'
*/
const SwitchAccountControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SwitchAccountController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\SwitchAccountController::__invoke
* @see app/Http/Controllers/Settings/SwitchAccountController.php:12
* @route '/settings/switch-account'
*/
SwitchAccountControllerForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: SwitchAccountController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

SwitchAccountController.form = SwitchAccountControllerForm

export default SwitchAccountController