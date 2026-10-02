# What's new in 0.46.0

## Sealed leagues

Sealed leagues are now tracked properly. Before, a sealed run was mistaken for a constructed league, counted out of five matches and mixed in with your constructed stats. Now it shows up under **Limited** as a sealed run, counted out of six matches, with the number of packs you opened.

Sealed has no picks to review, so a sealed run skips the Draft page and opens on its deck. Your card pool is worked out from the deck you register, so every card you opened is there. If you add the extra booster after match 3, its cards are marked **Added booster**.

Any sealed runs the app already recorded are fixed automatically.

## A new limited deck view

![The limited deck page: the main deck as card images grouped by type, with the mana curve and colours of the deck and the card pool](/content/whats-new/sealed-deck.jpg)

The deck page for drafts and sealed runs has been redesigned to look like your constructed decklists.

- **Maindeck** shows your 40 cards as card images, grouped by type and sorted by mana cost.
- **Card pool** shows everything you didn't play, grouped by colour.
- The mana curve and colour split are shown for both your deck and your whole pool, so you can see which colours your pool was deep in.

## Other improvements

- The **Cards** page for a sealed run lists your whole pool, with where each card ended up and how it played.
- Sealed runs now show their set name and cover art, taken from the cards you opened.
- The Limited list shows one result dot per match in the run, so a sealed run shows six.

## A new league window

The league window has been redesigned. It now shows which league you're in, your record, and what's happening right now, with your deck's artwork behind it if you like.

![The league window in each state: in game, sideboarding, waiting for an opponent, run complete, trophy and dropped, plus the compact size](/content/whats-new/league-overlay.png)

- **In game** shows which match you're on and your game wins and losses so far.
- **Sideboarding** turns the card amber between games, so you can't miss it.
- **Waiting for an opponent** keeps your artwork on screen while you queue.
- **Run complete** shows your final record and your games won and lost. Go 5-0 and you get a **Trophied!** card.
- The card's border takes on your deck's colours.

If the MTGO helper is turned on, sideboarding and new games show up on the card as they happen, without waiting for MTGO's logs.

The card shows your deck's archetype, like "Mono Green Tron", rather than whatever you named the deck in MTGO. Click the name on the card to type your own; it's remembered for that deck.

Go to **Settings > Overlays** to choose the artwork (your deck's cover, no artwork, or your own image) and the size (**Full** or **Compact**).

## Show your league in OBS

You can now put your league card on stream without having the league window open. In **Settings > Overlays**, turn on **Publish live overlay** under **Stream overlay (OBS)**. Copy the link it gives you and add it to OBS as a **Browser Source**, at the size shown under the link. The background is see-through, so the card sits straight on top of your stream.

The card updates on stream within a second or two of anything changing. It works on a second streaming PC too, because the link isn't tied to the computer you play on.

Publishing is off until you turn it on. You need to be signed in to MyMTGO, and anyone with the link can see your current league. If something stops it publishing, the settings page tells you why.

## Better opponent info on the game overlay

When you're paired, the game overlay now works out what your opponent is likely playing in this order:

- **What you faced them on last time** in this format, labelled **you faced**.
- **A 5-0 list** they've published.
- **The last deck they played** if they use MyMTGO too.
- **What other MyMTGO players saw them on.**

As soon as they reveal enough cards to show they're on something else, the overlay switches to that.

Your history with them now reads like **Met 3× · won 2**, and it only counts matches in the format you're playing.

## Clock and game length on Game Stats

A deck's **Game Stats** page has three new columns:

- **Time/G** is how long your games take on average.
- **Clock left** is how much of your MTGO clock you had left at the end of each game. On the **All Games** rows, it's what you had left when the match ended.
- **Opp clock left** is the same for your opponent, so you can see whether you're the one running low.

Every row splits these by game number and by play or draw, so you can spot where you lose time. The clock is only recorded while the MTGO helper is running, so clock stats build up from the games you play with it on.

## Misc UI tweaks

- The "per page" picker on the Decks page no longer cuts off its label.
- Limited match tables now sit in a card like the other tables.
- The result and type filters on a deck's Matches tab no longer cut off "All Results".
- The **Opponents** page has been removed from the menu. Your record against an opponent still shows on the game overlay when you're paired with someone you've played before.
