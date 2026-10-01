# What's new in 0.46.0

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
