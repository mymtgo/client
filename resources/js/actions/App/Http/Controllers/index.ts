import Settings from './Settings'
import IndexController from './IndexController'
import Matches from './Matches'
import Games from './Games'
import Leagues from './Leagues'
import Opponents from './Opponents'
import Decks from './Decks'

const Controllers = {
    Settings: Object.assign(Settings, Settings),
    IndexController: Object.assign(IndexController, IndexController),
    Matches: Object.assign(Matches, Matches),
    Games: Object.assign(Games, Games),
    Leagues: Object.assign(Leagues, Leagues),
    Opponents: Object.assign(Opponents, Opponents),
    Decks: Object.assign(Decks, Decks),
}

export default Controllers