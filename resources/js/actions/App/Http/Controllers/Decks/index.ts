import IndexController from './IndexController'
import ShowController from './ShowController'

const Decks = {
    IndexController: Object.assign(IndexController, IndexController),
    ShowController: Object.assign(ShowController, ShowController),
}

export default Decks