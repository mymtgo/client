<?php

use App\Actions\Archetypes\ParseDekFile;

it('parses a dek file into card entries', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Deck xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <NetDeckID>0</NetDeckID>
  <PreconstructedDeckID>0</PreconstructedDeckID>
  <Cards CatID="12345" Quantity="4" Sideboard="false" Name="Lightning Bolt" Annotation="0"/>
  <Cards CatID="67890" Quantity="2" Sideboard="true" Name="Path to Exile" Annotation="0"/>
</Deck>
XML;

    $result = ParseDekFile::run($xml);

    expect($result)->toHaveCount(2);
    expect($result[0])->toMatchArray([
        'mtgo_id' => 12345,
        'quantity' => 4,
        'sideboard' => false,
    ]);
    expect($result[1])->toMatchArray([
        'mtgo_id' => 67890,
        'quantity' => 2,
        'sideboard' => true,
    ]);
});

it('handles empty deck file', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Deck xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <NetDeckID>0</NetDeckID>
  <PreconstructedDeckID>0</PreconstructedDeckID>
</Deck>
XML;

    $result = ParseDekFile::run($xml);

    expect($result)->toBeEmpty();
});

it('throws on invalid xml', function () {
    ParseDekFile::run('not xml at all');
})->throws(RuntimeException::class);
