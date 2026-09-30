# What's new in 0.41.0

## Choose where card images are stored

If you download card images locally, they can take up a fair bit of space. You can now keep them on a different drive, which helps if your system drive is a small SSD.

Go to **Settings > Storage**, find **Card images** and click **Change** next to **Image folder**. Pick any folder and mymtgo moves your existing images across for you. Images go into their own `mymtgo-card-images` folder inside the one you pick, so nothing else in that folder is touched.

Changed your mind? **Reset to default** moves them back.

If the drive is ever disconnected, mymtgo keeps working. Downloaded images won't show and new downloads pause until the drive is back.

## Recently played decks

The **Decks** page now opens on your 8 most recently played decks, so the ones you're playing right now are one click away. You'll find **Recently played** at the top of the archetype list in the sidebar. Pick another archetype, or click **Recently played** again to see every deck.

Archetypes in the sidebar are now listed alphabetically, which makes them easier to find, and **Other archetype…** has moved to the top of the list so it no longer gets lost at the bottom.

## Collapse the game overlay

The game overlay no longer has to cover your game log or chat when you want to read them. Click the arrow next to the overlay's tabs to collapse it down to just the header and tabs. Click it again, or click any tab, to open it back up at the size you had it. The overlay remembers whether it was collapsed, so it stays the way you left it next time.

## See what your opponent could still have

Once your opponent's archetype is known, the overlay's **Revealed** tab now goes beyond the cards they've shown. Under what they've revealed you'll find **Could still have**: cards that archetype usually plays, grouped by type like the rest of the overlay, with how many copies could still be in their deck. Below that, **Potential sideboard cards** lists what they might bring in after game 1. The counts go down as your opponent reveals copies, and a card drops off once you've seen them all.

## Fixes

- Draw odds in the game overlay now count double-faced cards played as their back face. Playing Witch Enchanter as Witch-Blessed Meadow, for example, used to leave all 4 copies counted as still in your library.
