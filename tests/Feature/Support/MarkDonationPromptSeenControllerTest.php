<?php

use App\Facades\AppSettings;

it('marks the donation prompt as seen', function () {
    AppSettings::setDonationPromptSeen(false);

    $this->post(route('support.donation.seen'))
        ->assertRedirect();

    expect(AppSettings::donationPromptSeen())->toBeTrue();
});

it('is idempotent when the prompt is already seen', function () {
    AppSettings::setDonationPromptSeen(true);

    $this->post(route('support.donation.seen'))
        ->assertRedirect();

    expect(AppSettings::donationPromptSeen())->toBeTrue();
});
