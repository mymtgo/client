import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
 * @see routes/web.php:15
 * @route '/setup'
 */
export const configure = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: configure.url(options),
    method: 'post',
})

configure.definition = {
    methods: ["post"],
    url: '/setup',
} satisfies RouteDefinition<["post"]>

/**
 * @see routes/web.php:15
 * @route '/setup'
 */
configure.url = (options?: RouteQueryOptions) => {
    return configure.definition.url + queryParams(options)
}

/**
 * @see routes/web.php:15
 * @route '/setup'
 */
configure.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: configure.url(options),
    method: 'post',
})
const setup = {
    configure: Object.assign(configure, configure),
}

export default setup