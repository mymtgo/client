import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
 * @see app/Http/Controllers/Settings/IndexController.php:19
 * @route '/settings'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
 * @see app/Http/Controllers/Settings/IndexController.php:19
 * @route '/settings'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
 * @see app/Http/Controllers/Settings/IndexController.php:19
 * @route '/settings'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Settings\IndexController::__invoke
 * @see app/Http/Controllers/Settings/IndexController.php:19
 * @route '/settings'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
 * @route '/settings/log-path'
 */
export const logPath = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: logPath.url(options),
    method: 'patch',
})

logPath.definition = {
    methods: ["patch"],
    url: '/settings/log-path',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
 * @route '/settings/log-path'
 */
logPath.url = (options?: RouteQueryOptions) => {
    return logPath.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateLogPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateLogPathController.php:13
 * @route '/settings/log-path'
 */
logPath.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: logPath.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
 * @route '/settings/data-path'
 */
export const dataPath = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: dataPath.url(options),
    method: 'patch',
})

dataPath.definition = {
    methods: ["patch"],
    url: '/settings/data-path',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
 * @route '/settings/data-path'
 */
dataPath.url = (options?: RouteQueryOptions) => {
    return dataPath.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateDataPathController::__invoke
 * @see app/Http/Controllers/Settings/UpdateDataPathController.php:13
 * @route '/settings/data-path'
 */
dataPath.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: dataPath.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\UpdateWatcherController::__invoke
 * @see app/Http/Controllers/Settings/UpdateWatcherController.php:13
 * @route '/settings/watcher'
 */
export const watcher = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: watcher.url(options),
    method: 'patch',
})

watcher.definition = {
    methods: ["patch"],
    url: '/settings/watcher',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateWatcherController::__invoke
 * @see app/Http/Controllers/Settings/UpdateWatcherController.php:13
 * @route '/settings/watcher'
 */
watcher.url = (options?: RouteQueryOptions) => {
    return watcher.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateWatcherController::__invoke
 * @see app/Http/Controllers/Settings/UpdateWatcherController.php:13
 * @route '/settings/watcher'
 */
watcher.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: watcher.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
 * @see app/Http/Controllers/Settings/RunIngestController.php:13
 * @route '/settings/ingest'
 */
export const ingest = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ingest.url(options),
    method: 'post',
})

ingest.definition = {
    methods: ["post"],
    url: '/settings/ingest',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
 * @see app/Http/Controllers/Settings/RunIngestController.php:13
 * @route '/settings/ingest'
 */
ingest.url = (options?: RouteQueryOptions) => {
    return ingest.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunIngestController::__invoke
 * @see app/Http/Controllers/Settings/RunIngestController.php:13
 * @route '/settings/ingest'
 */
ingest.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ingest.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
 * @see app/Http/Controllers/Settings/RunSyncController.php:13
 * @route '/settings/sync'
 */
export const sync = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: sync.url(options),
    method: 'post',
})

sync.definition = {
    methods: ["post"],
    url: '/settings/sync',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
 * @see app/Http/Controllers/Settings/RunSyncController.php:13
 * @route '/settings/sync'
 */
sync.url = (options?: RouteQueryOptions) => {
    return sync.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunSyncController::__invoke
 * @see app/Http/Controllers/Settings/RunSyncController.php:13
 * @route '/settings/sync'
 */
sync.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: sync.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
 * @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
 * @route '/settings/populate-cards'
 */
export const populateCards = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: populateCards.url(options),
    method: 'post',
})

populateCards.definition = {
    methods: ["post"],
    url: '/settings/populate-cards',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
 * @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
 * @route '/settings/populate-cards'
 */
populateCards.url = (options?: RouteQueryOptions) => {
    return populateCards.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunPopulateCardsController::__invoke
 * @see app/Http/Controllers/Settings/RunPopulateCardsController.php:11
 * @route '/settings/populate-cards'
 */
populateCards.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: populateCards.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
export const anonymousStats = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: anonymousStats.url(options),
    method: 'patch',
})

anonymousStats.definition = {
    methods: ["patch"],
    url: '/settings/anonymous-stats',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
anonymousStats.url = (options?: RouteQueryOptions) => {
    return anonymousStats.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateAnonymousStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateAnonymousStatsController.php:12
 * @route '/settings/anonymous-stats'
 */
anonymousStats.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: anonymousStats.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
export const shareStats = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: shareStats.url(options),
    method: 'patch',
})

shareStats.definition = {
    methods: ["patch"],
    url: '/settings/share-stats',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
shareStats.url = (options?: RouteQueryOptions) => {
    return shareStats.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateShareStatsController::__invoke
 * @see app/Http/Controllers/Settings/UpdateShareStatsController.php:12
 * @route '/settings/share-stats'
 */
shareStats.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: shareStats.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
 * @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
 * @route '/settings/hide-phantom'
 */
export const hidePhantom = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: hidePhantom.url(options),
    method: 'patch',
})

hidePhantom.definition = {
    methods: ["patch"],
    url: '/settings/hide-phantom',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
 * @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
 * @route '/settings/hide-phantom'
 */
hidePhantom.url = (options?: RouteQueryOptions) => {
    return hidePhantom.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\UpdateHidePhantomController::__invoke
 * @see app/Http/Controllers/Settings/UpdateHidePhantomController.php:12
 * @route '/settings/hide-phantom'
 */
hidePhantom.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: hidePhantom.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\RunSubmitMatchesController::__invoke
 * @see app/Http/Controllers/Settings/RunSubmitMatchesController.php:13
 * @route '/settings/submit-matches'
 */
export const submitMatches = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: submitMatches.url(options),
    method: 'post',
})

submitMatches.definition = {
    methods: ["post"],
    url: '/settings/submit-matches',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\RunSubmitMatchesController::__invoke
 * @see app/Http/Controllers/Settings/RunSubmitMatchesController.php:13
 * @route '/settings/submit-matches'
 */
submitMatches.url = (options?: RouteQueryOptions) => {
    return submitMatches.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\RunSubmitMatchesController::__invoke
 * @see app/Http/Controllers/Settings/RunSubmitMatchesController.php:13
 * @route '/settings/submit-matches'
 */
submitMatches.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: submitMatches.url(options),
    method: 'post',
})
const settings = {
    index: Object.assign(index, index),
logPath: Object.assign(logPath, logPath),
dataPath: Object.assign(dataPath, dataPath),
watcher: Object.assign(watcher, watcher),
ingest: Object.assign(ingest, ingest),
sync: Object.assign(sync, sync),
populateCards: Object.assign(populateCards, populateCards),
anonymousStats: Object.assign(anonymousStats, anonymousStats),
shareStats: Object.assign(shareStats, shareStats),
hidePhantom: Object.assign(hidePhantom, hidePhantom),
submitMatches: Object.assign(submitMatches, submitMatches),
}

export default settings