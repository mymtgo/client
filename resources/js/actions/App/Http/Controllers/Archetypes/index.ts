import IndexController from './IndexController'
import ShowController from './ShowController'
import DownloadDecklistController from './DownloadDecklistController'
import ExportDekController from './ExportDekController'

const Archetypes = {
    IndexController: Object.assign(IndexController, IndexController),
    ShowController: Object.assign(ShowController, ShowController),
    DownloadDecklistController: Object.assign(DownloadDecklistController, DownloadDecklistController),
    ExportDekController: Object.assign(ExportDekController, ExportDekController),
}

export default Archetypes