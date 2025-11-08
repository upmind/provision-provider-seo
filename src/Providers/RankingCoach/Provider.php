<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Seo\Providers\RankingCoach;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionProviders\Seo\Category;
use Upmind\ProvisionProviders\Seo\Data\AccountIdentifierParams;
use Upmind\ProvisionProviders\Seo\Data\ChangePackageParams;
use Upmind\ProvisionProviders\Seo\Data\CreateParams;
use Upmind\ProvisionProviders\Seo\Data\CreateResult;
use Upmind\ProvisionProviders\Seo\Data\EmptyResult;
use Upmind\ProvisionProviders\Seo\Data\LoginResult;
use Upmind\ProvisionProviders\Seo\Providers\RankingCoach\Data\Configuration;
use Upmind\ProvisionProviders\Seo\Providers\RankingCoach\Helper\RankingCoachApi;

/**
 * Ranking Coach provider.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;

    protected ?RankingCoachApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Ranking Coach')
            ->setDescription('Create, login to and delete Ranking Coach accounts')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/ranking-coach-logo@2x.png');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): CreateResult
    {
        try {
            if (empty($params->customer_name)) {
                $this->errorResult('Customer name is required!');
            }

            $this->api()->createAccount(
                (string) $params->customer_id,
                $params->customer_email,
                $params->customer_name,
                $params->domain
            );

            $this->api()->unsuspend((string) $params->customer_id);

            return CreateResult::create()
                ->setUsername((string) $params->customer_id)
                ->setDomain($params->domain)
                ->setPackageIdentifier($params->package_identifier)
                ->setMessage('Account created');

        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function login(AccountIdentifierParams $params): LoginResult
    {
        try {
            return LoginResult::create()->setUrl($this->api()->login($params->username));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws Throwable
     */
    public function changePackage(ChangePackageParams $params): EmptyResult
    {
        try {
            $this->api()->changePackage(
                (string)$params->username,
                $params->package_identifier,
            );

            return EmptyResult::create()->setMessage('Account updated');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function suspend(AccountIdentifierParams $params): EmptyResult
    {
        try {
            $info = $this->api()->getStatus((string)$params->username);

            if ($info === 'canceled') {
                return EmptyResult::create()->setMessage('Account already suspended');
            }

            $this->api()->suspend((string)$params->username);

            return EmptyResult::create()->setMessage('Account suspended');
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function unSuspend(AccountIdentifierParams $params): EmptyResult
    {
        try {
            $info = $this->api()->getStatus((string)$params->username);

            if ($info === 'active') {
                return EmptyResult::create()->setMessage('Account already unsuspended');
            }

            $this->api()->unsuspend((string)$params->username);

            return EmptyResult::create()->setMessage('Account unsuspended');
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function terminate(AccountIdentifierParams $params): EmptyResult
    {
        try {
            $this->api()->terminate((string)$params->username);

            return EmptyResult::create()->setMessage('Account suspended');
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function api(): RankingCoachApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->configuration->isSandbox() ?
                'https://www.rankingcoach.com/api_test/' :
                'https://www.rankingcoach.com/api/',
            RequestOptions::HEADERS => [
                'User-Agent' => 'upmind/provision-provider-seo v1.0',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            RequestOptions::TIMEOUT => 30, // seconds
            RequestOptions::CONNECT_TIMEOUT => 5, // seconds
            'handler' => $this->getGuzzleHandlerStack()
        ]);

        return $this->api = new RankingCoachApi($client, $this->configuration);
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e, $params = null): void
    {
        if (($e instanceof RequestException) && $e->hasResponse()) {
            $response = $e->getResponse();

            $body = trim($response === null ? '' : $response->getBody()->getContents());
            $responseData = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $errorMessage = $responseData['message'] ?? $response->getReasonPhrase();

            $this->errorResult(
                sprintf('Provider API Error: %s', $errorMessage),
                ['response_data' => $responseData ?? Str::limit($body, 300)],
                [],
                $e
            );
        }

        throw $e;
    }
}
