import IndexController from './IndexController'
import AbandonController from './AbandonController'

const Leagues = {
    IndexController: Object.assign(IndexController, IndexController),
    AbandonController: Object.assign(AbandonController, AbandonController),
}

export default Leagues