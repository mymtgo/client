<?php

/**
 * `decks/Dashboard.vue` is a thin page wrapper that enumerates its props and
 * hands them to `DeckDashboard.vue` one by one. Adding a prop to the child
 * without adding it to the wrapper leaves it `undefined` at runtime, which
 * blows up inside whichever card reads it and takes the page with it.
 *
 * That is a whole-page failure no action test can see, so it is checked here
 * rather than waiting for someone to open the deck dashboard.
 */
/** Unit tests do not boot the app, so the project root is resolved from this file. */
function dashboardSource(string $relativePath): string
{
    return file_get_contents(dirname(__DIR__, 3).'/resources/js/'.$relativePath);
}

function dashboardChildProps(): array
{
    $source = dashboardSource('pages/decks/partials/DeckDashboard.vue');

    preg_match('/defineProps<\{(.*?)\}>\(\)/s', $source, $match);

    preg_match_all('/^\s{4}([a-zA-Z][a-zA-Z0-9]*)\??:/m', $match[1] ?? '', $props);

    return $props[1] ?? [];
}

function dashboardForwardedProps(): array
{
    $source = dashboardSource('pages/decks/Dashboard.vue');

    preg_match('/<DeckDashboard(.*?)\/>/s', $source, $match);

    preg_match_all('/:?([a-z][a-z0-9-]*)=/', $match[1] ?? '', $attributes);

    return array_map(
        fn (string $attribute) => lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $attribute)))),
        $attributes[1] ?? [],
    );
}

it('hands every prop the deck dashboard declares down from the page', function () {
    $missing = array_diff(dashboardChildProps(), dashboardForwardedProps());

    expect($missing)->toBeEmpty(
        'DeckDashboard.vue declares props the page never passes: '.implode(', ', $missing),
    );
});

it('found props to compare, so a broken parser cannot pass silently', function () {
    expect(dashboardChildProps())->not->toBeEmpty()
        ->and(dashboardForwardedProps())->not->toBeEmpty();
});
