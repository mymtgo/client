<?php

namespace App\Enums;

/**
 * Which cards BuildSideboardGuide lists.
 *
 * History: what the overlay shows with no authored plan. Every sideboard card,
 * plus maindeck cards that have actually been cut (locally or by the field).
 *
 * Plan: only the cards the player's guide names, with planned quantities. What
 * the overlay shows once a guide exists.
 *
 * Editor: every sideboard card and every maindeck card, with the guide's
 * planned quantities attached, for the guide editor to build steppers from.
 */
enum SideboardGuideScope: string
{
    case History = 'history';
    case Plan = 'plan';
    case Editor = 'editor';
}
