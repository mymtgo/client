# mymtgo

A free, local desktop app for tracking your Magic: The Gathering Online matches. Every match. Fully tracked.

<img width="3810" height="3054" alt="image" src="https://github.com/user-attachments/assets/29cc27a9-d59f-4fb7-9b3f-b7a2aca724b9" />

## What is mymtgo?

mymtgo is a desktop companion for competitive MTGO players who care about their data. It automatically parses your MTGO log files and builds a complete picture of your match history, win rates, and performance trends — no manual entry required.

Everything runs locally. Your data stays yours.

## Features

- **Automatic match tracking** — log parsing, per-game timelines, and archetype detection
- **Performance dashboard** — win rates, play/draw stats, and performance charts over time
- **Archetype matchup breakdowns** — see how you perform against every deck
- **Match detail drill-down** — opening hands, mulligans, sideboard tracking
- **League progress tracker** — track your league runs from start to finish
- **Opponent history and scouting** — review past opponents and their tendencies
- **Live decklist overlay** — pop out your deck list during matches
- **Deck versioning** — automatic snapshots synced from your MTGO deck files

## How it works

mymtgo reads your local MTGO log files and deck XMLs passively. It does not inject into, modify, or interact with the MTGO client in any way.

```
MTGO log files → mymtgo → SQLite (local)
```

## Getting started

### Prerequisites

- PHP 8.4
- Node.js
- Composer

### Installation

```bash
composer install
npm install
php artisan migrate
php artisan native:serve
```

## Tech stack

- Laravel 12 / PHP 8.4
- NativePHP (Electron)
- Vue 3 / Inertia.js v2
- Tailwind CSS v4
- SQLite

## License

MIT

## Disclaimer

Magic: The Gathering and Magic: The Gathering Online (MTGO) are trademarks of Wizards of the Coast LLC. mymtgo is not affiliated with, endorsed by, or sponsored by Wizards of the Coast.
