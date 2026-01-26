import ShowController from './ShowController'
import DeleteController from './DeleteController'

const Matches = {
    ShowController: Object.assign(ShowController, ShowController),
    DeleteController: Object.assign(DeleteController, DeleteController),
}

export default Matches