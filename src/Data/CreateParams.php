<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Seo\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read mixed $customer_id Id of the customer in the billing system
 * @property-read string $customer_email Email address of the customer
 * @property-read string|null $customer_name Name of the customer
 * @property-read string|null $customer_phone Phone number of the customer in international format
 * @property-read CustomerAddressParams|null $customer_address Address of the customer
 * @property-read string $domain Domain name the account is for
 * @property-read string $package_identifier Service package identifier, if any
 * @property-read string[]|null $promo_codes Optional array of promo codes applied to the order
 * @property-read mixed[]|null $extra Any extra data to pass to the service endpoint
 * @property-read string|null $service_id The Product/Service ID in the system
 */
class CreateParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'customer_id' => ['required'],
            'customer_email' => ['required', 'email'],
            'customer_name' => ['nullable', 'string'],
            'customer_phone' => ['nullable', 'string', 'international_phone'],
            'customer_address' => ['nullable', CustomerAddressParams::class],
            'domain' => ['required', 'string', 'alpha-dash-dot'],
            'package_identifier' => ['required', 'string'],
            'promo_codes' => ['nullable', 'array'],
            'promo_codes.*' => ['string'],
            'extra' => ['nullable', 'array'],
            'service_id' => ['nullable', 'string'],
        ]);
    }
}
