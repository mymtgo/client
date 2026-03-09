import IndexController from './IndexController'
import ShowController from './ShowController'
import PopoutController from './PopoutController'
import OpenPopoutController from './OpenPopoutController'
const Decks = {
    IndexController: Object.assign(IndexController, IndexController),
ShowController: Object.assign(ShowController, ShowController),
PopoutController: Object.assign(PopoutController, PopoutController),
OpenPopoutController: Object.assign(OpenPopoutController, OpenPopoutController),
}

export default Decks