# MTGO Challenge/Tournament Data Analysis

Data extracted from a single MTGO session (2026-03-18, ~1.5 hours).

**Note:** This user did NOT participate in any challenges — all data is broadcast by MTGO to every connected client.

## Tournament Lifecycle Events

| Time | Tournament Token | Transition |
|------|-----------------|------------|
| 18:42:27 | `b049851f-3a2...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `fcbde3ef-75b...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `d9767190-9fb...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `4eaa2190-1ff...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `b041a62b-47a...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `a1723584-7be...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `df78b6e1-ad5...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `0e681ba3-509...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `709b68a8-9d9...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `390adbd6-a75...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `62085bd2-824...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `e40a423b-408...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `039362db-c24...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `7ef3ce2f-58c...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `cddb8e04-405...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `e068badd-eb1...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `e594e1a4-1ce...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `07109ebb-f5a...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `4ac8cd5f-875...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `d4538780-bad...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `5873411c-1f3...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `607fbcd4-ade...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `b370ccd2-1b3...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `08563924-c7b...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |
| 18:42:27 | `513dabf4-950...` | `UninitializedState` → `NotJoinedAwaitingMinPlayers` |

*157 total transitions across 108 tournaments*

## Round Results (FlsTournamentRoundResultMessage)

Full standings broadcast after each round. Example from one tournament:

**Tournament:** `18c84071-d8b...` | **Round:** 3

| Rank | LoginID | Points | Record (by round) | Opp Match Win% | Game Win% |
|------|---------|--------|-------------------|----------------|-----------|
| 1 | 2903591 | 9 | 2-0, 2-1, 2-0 | 0.5556 | 0.8571 |
| 2 | 2968680 | 9 | 2-0, 2-0, 2-1 | 0.5556 | 0.8571 |
| 3 | 319713 | 9 | 2-1, 2-0, 2-0 | 0.5556 | 0.8571 |
| 4 | 3437119 | 9 | 2-1, 2-0, 2-0 | 0.5556 | 0.8571 |
| 5 | 2705240 | 9 | 2-1, 2-0, 2-0 | 0.5556 | 0.8571 |
| 6 | 3075908 | 9 | 2-1, 2-1, 2-0 | 0.5556 | 0.7500 |
| 7 | 3083416 | 9 | 2-0, 2-1, 2-1 | 0.5556 | 0.7500 |
| 8 | 1942357 | 9 | 2-0, 2-0, 2-1 | 0.4444 | 0.8571 |
| 9 | 2702142 | 9 | 2-1, 2-0, 2-0 | 0.4444 | 0.8571 |
| 10 | 2872570 | 9 | 2-1, 2-0, 2-1 | 0.4444 | 0.7500 |
| 11 | 2099301 | 9 | 2-1, 2-1, 2-1 | 0.4444 | 0.6667 |
| 12 | 2129541 | 6 | 2-0, 2-1, 1-2 | 0.7778 | 0.6250 |
| 13 | 2646466 | 6 | 2-1, 2-0, 0-2 | 0.7778 | 0.5714 |
| 14 | 3154401 | 6 | 2-1, 2-0, 1-2 | 0.6667 | 0.6250 |
| 15 | 2788809 | 6 | 2-0, 2-1, 1-2 | 0.6667 | 0.6250 |
| 16 | 2720911 | 6 | 2-0, 2-1, 0-2 | 0.6667 | 0.5714 |

## Player Eliminations (FlsTournamentPlayerIsEliminatedMessage)

| Tournament Token | LoginID | Reason |
|-----------------|---------|--------|
| `6eaaa32d-de6...` | 829651 | Match Loss |
| `e63ba74a-50e...` | 3468343 | Drop |
| `4548c22c-dc2...` | 1035251 | Match Loss |
| `6eaaa32d-de6...` | 1993659 | Match Loss |
| `18c84071-d8b...` | 1182549 | Drop |
| `6eaaa32d-de6...` | 1785352 | Match Loss |
| `18c84071-d8b...` | 926277 | Drop |
| `18c84071-d8b...` | 2412485 | Drop |
| `43bd3465-f61...` | 2180371 | Drop |
| `6eaaa32d-de6...` | 2664504 | Match Loss |

*32 total eliminations*

## Tournament Completions (FlsTournamentEndRespMessage)

| Tournament Token | End Date | LoginID |
|-----------------|----------|---------|
| `e63ba74a-50e...` | 2026-03-18T19:07:06 | 1099 |
| `9b048ec4-aa1...` | 2026-03-18T19:14:24 | 1099 |
| `12e1b620-ad9...` | 2026-03-18T19:26:42 | 1099 |
| `6eaaa32d-de6...` | 2026-03-18T19:38:19 | 1099 |
| `4548c22c-dc2...` | 2026-03-18T19:53:19 | 1099 |

## TournamentMatch Events

State changes for individual matches within tournaments:

| Transition | Count |
|-----------|-------|
| `UninitializedState` → `ClosedState)` | 221 |
| `NotJoinedEventUnderwayState` → `NotJoinedSideboardingState)` | 200 |
| `NotJoinedSideboardingState` → `NotJoinedEventUnderwayState)` | 183 |
| `UninitializedState` → `NotJoinedEventUnderwayState)` | 166 |
| `NotJoinedEventUnderwayState` → `ClosedState)` | 119 |
| `NotJoinedSideboardingState` → `NotJoinedWaitingForGameToStartState)` | 9 |
| `NotJoinedWaitingForGameToStartState` → `NotJoinedEventUnderwayState)` | 9 |
| `NotJoinedSideboardingState` → `ClosedState)` | 3 |
| `UninitializedState` → `NotJoinedSideboardingState)` | 1 |


## Summary

Even without participating, MTGO broadcasts rich tournament data including:

- Full per-round standings with W-L records and tiebreaker percentages

- Real-time elimination notifications

- Tournament lifecycle (firing, rounds, completion)

- Individual match state transitions within the tournament


If the user **participates**, the `TournamentMatch` states would use `Joined` variants

(like league matches), and per-game data would be available from `.dat` files.
