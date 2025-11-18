<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Seo\Providers\RankingCoach\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Ranking Coach API credentials.
 *
 * @property-read string $username username
 * @property-read string $password password
 * @property-read bool|null $sandbox
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function isSandbox(): bool
    {
        return (bool)$this->sandbox;
    }
}
