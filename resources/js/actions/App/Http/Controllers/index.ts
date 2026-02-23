import IndexController from './IndexController'
import Matches from './Matches'
import Games from './Games'
import Leagues from './Leagues'
import Opponents from './Opponents'
import Decks from './Decks'
import Settings from './Settings'
const Controllers = {
    IndexController: Object.assign(IndexController, IndexController),
Matches: Object.assign(Matches, Matches),
Games: Object.assign(Games, Games),
Leagues: Object.assign(Leagues, Leagues),
Opponents: Object.assign(Opponents, Opponents),
Decks: Object.assign(Decks, Decks),
Settings: Object.assign(Settings, Settings),
}

export default Controllers