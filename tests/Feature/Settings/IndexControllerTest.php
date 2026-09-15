<?php

it('redirects the settings index to the general page', function () {
    $this->get(route('settings.index'))
        ->assertRedirect(route('settings.general'));
});
