import IndexController from './IndexController'
import OverlayController from './OverlayController'
import ToggleOverlayController from './ToggleOverlayController'
import AbandonController from './AbandonController'

const Leagues = {
    IndexController: Object.assign(IndexController, IndexController),
    OverlayController: Object.assign(OverlayController, OverlayController),
    ToggleOverlayController: Object.assign(ToggleOverlayController, ToggleOverlayController),
    AbandonController: Object.assign(AbandonController, AbandonController),
}

export default Leagues