import IndexController from './IndexController'
import OverlayController from './OverlayController'
import AbandonController from './AbandonController'

const Leagues = {
    IndexController: Object.assign(IndexController, IndexController),
    OverlayController: Object.assign(OverlayController, OverlayController),
    AbandonController: Object.assign(AbandonController, AbandonController),
}

export default Leagues