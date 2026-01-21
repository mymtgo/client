import IndexController from './IndexController'
import Matches from './Matches'
import Decks from './Decks'
const Controllers = {
    IndexController: Object.assign(IndexController, IndexController),
Matches: Object.assign(Matches, Matches),
Decks: Object.assign(Decks, Decks),
}

export default Controllers