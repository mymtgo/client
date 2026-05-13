<?php

namespace App\Support;

use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\Card;
use App\Models\MtgoMatch;

class PipelineContext
{
    /** @var array<string, ?MtgoMatch> */
    private array $matchByToken = [];

    /** @var array<string, ?Account> */
    private array $accountByUsername = [];

    /** @var array<int, ?string> */
    private array $oracleByMultiverseId = [];

    private ?string $localUsername = null;

    public function matchByToken(string $token): ?MtgoMatch
    {
        if (! array_key_exists($token, $this->matchByToken)) {
            $this->matchByToken[$token] = MtgoMatch::where('token', $token)->first();
        }

        return $this->matchByToken[$token];
    }

    public function rememberMatch(MtgoMatch $match): void
    {
        $this->matchByToken[$match->token] = $match;
    }

    public function accountByUsername(string $username): ?Account
    {
        if (! array_key_exists($username, $this->accountByUsername)) {
            $this->accountByUsername[$username] = Account::where('username', $username)->first();
        }

        return $this->accountByUsername[$username];
    }

    public function oracleByMultiverseId(int $multiverseId): ?string
    {
        if (! array_key_exists($multiverseId, $this->oracleByMultiverseId)) {
            $this->oracleByMultiverseId[$multiverseId] = Card::where('mtgo_id', $multiverseId)->value('oracle_id');
        }

        return $this->oracleByMultiverseId[$multiverseId];
    }

    public function localUsername(): ?string
    {
        if ($this->localUsername === null) {
            $resolved = Mtgo::resolveUsername();
            if ($resolved !== null) {
                $this->localUsername = $resolved;
            }
        }

        return $this->localUsername;
    }

    public function setLocalUsername(string $username): void
    {
        $this->localUsername = $username;
    }

    public function reset(): void
    {
        $this->matchByToken = [];
        $this->accountByUsername = [];
        $this->oracleByMultiverseId = [];
        $this->localUsername = null;
    }
}
