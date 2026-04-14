<?php

use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

describe('create', function () {
    it('renders the create page', function () {
        $response = $this->get('/archetypes/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('archetypes/Create')
            ->has('archetypes')
            ->has('formats')
        );
    });
});

describe('store', function () {
    it('creates a manual archetype', function () {
        Settings::set('device_id', 'abcdef1234567890');

        $card = Card::create([
            'oracle_id' => 'oracle-bolt',
            'mtgo_id' => 12345,
            'name' => 'Lightning Bolt',
            'type' => 'Instant',
        ]);

        $response = $this->post('/archetypes', [
            'name' => 'My Burn Deck',
            'format' => 'modern',
            'color_identity' => 'R',
            'cards' => [
                [
                    'oracle_id' => 'oracle-bolt',
                    'mtgo_id' => 12345,
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'quantity' => 4,
                    'sideboard' => false,
                ],
            ],
        ]);

        $response->assertRedirect();

        $archetype = Archetype::where('name', 'My Burn Deck')->first();
        expect($archetype)->not->toBeNull();
        expect($archetype->manual)->toBeTrue();
        expect($archetype->cards)->toHaveCount(1);
    });

    it('validates required fields', function () {
        $response = $this->post('/archetypes', []);

        $response->assertSessionHasErrors(['name', 'format', 'cards']);
    });
});

describe('edit', function () {
    it('renders the edit page', function () {
        $archetype = Archetype::factory()->withDecklist()->create();

        $response = $this->get("/archetypes/{$archetype->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('archetypes/Edit')
            ->has('archetype')
            ->has('cards')
        );
    });
});

describe('update', function () {
    it('updates an archetype and sets it to manual', function () {
        $archetype = Archetype::factory()->withDecklist()->create([
            'manual' => false,
        ]);

        $card = Card::create([
            'oracle_id' => 'oracle-new',
            'mtgo_id' => 22222,
            'name' => 'New Card',
            'type' => 'Instant',
        ]);

        $response = $this->put("/archetypes/{$archetype->id}", [
            'name' => 'Updated Name',
            'format' => 'legacy',
            'color_identity' => 'U',
            'cards' => [
                [
                    'oracle_id' => 'oracle-new',
                    'mtgo_id' => 22222,
                    'name' => 'New Card',
                    'type' => 'Instant',
                    'quantity' => 3,
                    'sideboard' => false,
                ],
            ],
        ]);

        $response->assertRedirect();
        expect($archetype->fresh()->manual)->toBeTrue();
        expect($archetype->fresh()->name)->toBe('Updated Name');
    });
});

describe('destroy', function () {
    it('deletes a manual archetype', function () {
        $archetype = Archetype::factory()->manual()->create();

        $response = $this->delete("/archetypes/{$archetype->id}");

        $response->assertRedirect();
        expect(Archetype::find($archetype->id))->toBeNull();
    });

    it('refuses to delete a non-manual archetype', function () {
        $archetype = Archetype::factory()->create(['manual' => false]);

        $response = $this->delete("/archetypes/{$archetype->id}");

        $response->assertForbidden();
        expect(Archetype::find($archetype->id))->not->toBeNull();
    });
});
