import IndexController from './IndexController'
import OverlayController from './OverlayController'
import ToggleOverlayController from './ToggleOverlayController'
import OpenOverlayController from './OpenOverlayController'
import AbandonController from './AbandonController'
const Leagues = {
    IndexController: Object.assign(IndexController, IndexController),
OverlayController: Object.assign(OverlayController, OverlayController),
ToggleOverlayController: Object.assign(ToggleOverlayController, ToggleOverlayController),
OpenOverlayController: Object.assign(OpenOverlayController, OpenOverlayController),
AbandonController: Object.assign(AbandonController, AbandonController),
}

export default Leagues