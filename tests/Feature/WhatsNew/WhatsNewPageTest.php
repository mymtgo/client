<?php

use App\Actions\WhatsNew\WhatsNewContent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->contentPath = sys_get_temp_dir().'/whats-new-'.uniqid().'.md';
    WhatsNewContent::usePath($this->contentPath);
});

afterEach(function () {
    @unlink($this->contentPath);
    WhatsNewContent::usePath('/nonexistent/whats-new.md');
});

it('renders the markdown file with tables and images', function () {
    file_put_contents($this->contentPath, "# New in 0.45\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n![Deck page](/content/whats-new/deck.png)\n");

    $this->get(route('whats-new'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('whats-new/Show')
            ->where('html', fn (string $html) => str_contains($html, '<h1>New in 0.45</h1>')
                && str_contains($html, '<table>')
                && str_contains($html, '<img src="/content/whats-new/deck.png" alt="Deck page"'))
        );
});

it('404s when the file is missing', function () {
    $this->get(route('whats-new'))->assertNotFound();
});

it('404s when the file is blank', function () {
    file_put_contents($this->contentPath, "  \n");

    $this->get(route('whats-new'))->assertNotFound();
});

it('shares whether the page exists', function (bool $exists) {
    if ($exists) {
        file_put_contents($this->contentPath, '# Hi');
    }

    $this->get(route('settings.general'))
        ->assertInertia(fn ($page) => $page->where('whatsNewAvailable', $exists));
})->with(['present' => true, 'missing' => false]);
