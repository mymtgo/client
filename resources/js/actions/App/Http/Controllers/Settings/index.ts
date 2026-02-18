import IndexController from './IndexController'
import UpdateLogPathController from './UpdateLogPathController'
import UpdateDataPathController from './UpdateDataPathController'
import UpdateWatcherController from './UpdateWatcherController'
import RunIngestController from './RunIngestController'
import RunSyncController from './RunSyncController'
import UpdateAnonymousStatsController from './UpdateAnonymousStatsController'

const Settings = {
    IndexController: Object.assign(IndexController, IndexController),
    UpdateLogPathController: Object.assign(UpdateLogPathController, UpdateLogPathController),
    UpdateDataPathController: Object.assign(UpdateDataPathController, UpdateDataPathController),
    UpdateWatcherController: Object.assign(UpdateWatcherController, UpdateWatcherController),
    RunIngestController: Object.assign(RunIngestController, RunIngestController),
    RunSyncController: Object.assign(RunSyncController, RunSyncController),
    UpdateAnonymousStatsController: Object.assign(UpdateAnonymousStatsController, UpdateAnonymousStatsController),
}

export default Settings