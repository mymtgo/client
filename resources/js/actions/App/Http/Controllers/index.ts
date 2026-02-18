import IndexController from './IndexController'
import Matches from './Matches'
import Games from './Games'
import Leagues from './Leagues'
import Decks from './Decks'

const Controllers = {
    IndexController: Object.assign(IndexController, IndexController),
    Matches: Object.assign(Matches, Matches),
    Games: Object.assign(Games, Games),
    Leagues: Object.assign(Leagues, Leagues),
    Decks: Object.assign(Decks, Decks),
}

export default Controllers