import IndexController from './IndexController'
import UpdateLogPathController from './UpdateLogPathController'
import UpdateDataPathController from './UpdateDataPathController'
import UpdateWatcherController from './UpdateWatcherController'
import RunIngestController from './RunIngestController'
import RunSyncController from './RunSyncController'
import RunPopulateCardsController from './RunPopulateCardsController'
import UpdateAnonymousStatsController from './UpdateAnonymousStatsController'
import UpdateShareStatsController from './UpdateShareStatsController'
import UpdateHidePhantomController from './UpdateHidePhantomController'
import RunSubmitMatchesController from './RunSubmitMatchesController'

const Settings = {
    IndexController: Object.assign(IndexController, IndexController),
    UpdateLogPathController: Object.assign(UpdateLogPathController, UpdateLogPathController),
    UpdateDataPathController: Object.assign(UpdateDataPathController, UpdateDataPathController),
    UpdateWatcherController: Object.assign(UpdateWatcherController, UpdateWatcherController),
    RunIngestController: Object.assign(RunIngestController, RunIngestController),
    RunSyncController: Object.assign(RunSyncController, RunSyncController),
    RunPopulateCardsController: Object.assign(RunPopulateCardsController, RunPopulateCardsController),
    UpdateAnonymousStatsController: Object.assign(UpdateAnonymousStatsController, UpdateAnonymousStatsController),
    UpdateShareStatsController: Object.assign(UpdateShareStatsController, UpdateShareStatsController),
    UpdateHidePhantomController: Object.assign(UpdateHidePhantomController, UpdateHidePhantomController),
    RunSubmitMatchesController: Object.assign(RunSubmitMatchesController, RunSubmitMatchesController),
}

export default Settings