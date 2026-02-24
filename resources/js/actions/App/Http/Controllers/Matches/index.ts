import ShowController from './ShowController'
import UpdateArchetypeController from './UpdateArchetypeController'
import DeleteController from './DeleteController'

const Matches = {
    ShowController: Object.assign(ShowController, ShowController),
    UpdateArchetypeController: Object.assign(UpdateArchetypeController, UpdateArchetypeController),
    DeleteController: Object.assign(DeleteController, DeleteController),
}

export default Matches