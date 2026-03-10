import IndexController from './IndexController'
import OverlayController from './OverlayController'
import OpponentScoutWindowController from './OpponentScoutWindowController'
import AbandonController from './AbandonController'

const Leagues = {
    IndexController: Object.assign(IndexController, IndexController),
    OverlayController: Object.assign(OverlayController, OverlayController),
    OpponentScoutWindowController: Object.assign(OpponentScoutWindowController, OpponentScoutWindowController),
    AbandonController: Object.assign(AbandonController, AbandonController),
}

export default Leagues