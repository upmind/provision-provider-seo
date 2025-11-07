<?php

namespace Upmind\ProvisionProviders\Seo\Providers\RankingCoach\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionProviders\Seo\Providers\RankingCoach\Data\Configuration;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class RankingCoachApi
{
    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    private function resolveCommand(string $command): string
    {
        return $this->configuration->sandbox
            ? "/api_test/$command"
            : "/api/$command";
    }

    /**
     * @throws GuzzleException
     */
    public function makeRequest(string $command, ?array $body = null, ?string $method = 'POST'): ?array
    {
        $requestParams = [];

        $body['api_username'] = $this->configuration->username;
        $body['api_password'] = $this->configuration->password;
        $body = json_encode($body);

        $requestParams['body'] = $body;


        $response = $this->client->request($method, $this->resolveCommand($command), $requestParams);
        $result = $response->getBody()->getContents();

        $response->getBody()->close();

        if ($result === "") {
            return null;
        }

        return $this->parseResponseData($result);
    }

    /**
     * @throws ProvisionFunctionError
     */
    private function parseResponseData(string $result): array
    {
        $parsedResult = json_decode($result, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        if ($error = $this->getResponseErrorMessage($parsedResult)) {
            throw ProvisionFunctionError::create($error)
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        return $parsedResult;
    }

    /**
     * @param $responseData
     * @return string|null
     */
    protected function getResponseErrorMessage($responseData): ?string
    {

        $errorMessage = null;

        if (isset($responseData['status']) && $responseData['status'] == "error") {
            if (isset($responseData['message'])) {
                $errorMessage = $responseData['message'];
            }

            if (isset($responseData['additional_infos'])) {
                foreach ($responseData['additional_infos'] as $value) {
                    $errorMessage .= "$value. ";
                }
            }
        }

        return $errorMessage ?? null;
    }


    /**
     * @param string $userId
     * @return string
     * @throws GuzzleException
     */
    public function getStatus(string $userId): string
    {
        $account = $this->getAccountData($userId);
        return $account['customer_status'];
    }


    /**
     * @param string $userId
     * @return array
     * @throws GuzzleException
     */
    public function getAccountData(string $userId): array
    {

        try {
            $body = [
                'external_id' => $userId,
            ];

            return $this->makeRequest('get_user', $body)["additional_infos"];
        } catch (\Exception) {
            $body = [
                'email' => $userId,
            ];

            return $this->makeRequest('get_user', $body)["additional_infos"];
        }

    }

    /**
     * @param string $customerId
     * @param string $customerEmail
     * @param string $customerName
     * @return void
     * @throws GuzzleException
     */
    public function createAccount(
        string $customerId,
        string $customerEmail,
        string $customerName,
        string $domain,
    ): void
    {

        @[$firstName, $lastName] = explode(' ', $customerName, 2);

        $body = [
            'email' => $customerEmail,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'external_id' => $customerId,
            'api_params_domain' => $domain
        ];

        $this->makeRequest('update_user', $body);
    }

    /**
     * @param string $userId
     * @return void
     * @throws GuzzleException
     */
    public function suspend(string $userId): void
    {

        $body = [
            'external_id' => $userId,
        ];

        $this->makeRequest('deactivate_user', $body);
    }

    /**
     * @param string $userId
     * @return void
     * @throws GuzzleException
     */
    public function unsuspend(string $userId): void
    {
        $body = [
            'external_id' => $userId,
        ];

        $this->makeRequest("activate_user", $body);
    }

    /**
     * @param string $userId
     * @param string $planId
     * @return void
     * @throws GuzzleException
     */
    public function changePackage(string $userId, string $planId): void
    {

        $account = $this->getAccountData($userId);
        $currentSubscription = null;
        foreach ($account['subscriptions'] as $subscription) {
            if ($subscription['status'] == "active") {
                $currentSubscription = $subscription['id'];
            }
        }

        if (!$currentSubscription) {
            $this->unsuspend($userId);
            return;
        }

        if (!is_numeric($planId)) {
            $planId = $this->getSubscriptionId($planId);
        }

        $body = [
            'external_id' => $userId,
            'subscription_id' => $currentSubscription,
            'update_subscription_id' => $planId,
        ];

        $this->makeRequest("subscription_update", $body);
    }


    /**
     * @param string $userId
     * @return string Login URL
     * @throws GuzzleException
     */
    public function login(string $userId): string
    {

        $session = $this->getAccountData($userId)['session_id'];

        $username = $this->configuration->username;
        $password = $this->configuration->password;

        return "https://www.rankingcoach.com/index/login?session_id=$session&site_id=&api_username=$username&api_password=$password";
    }

    /**
     * @param string $userId
     * @return void
     * @throws GuzzleException
     */
    public function terminate(string $userId): void
    {

        $body = [
            'external_id' => $userId,
        ];

        //DEPRECATED $this->makeRequest('delete_user', $body);
        $this->makeRequest('deactivate_user', $body);
    }


    /**
     * @param string $package
     * @return int
     * @throws GuzzleException
     */
    private function getSubscriptionId(string $package): int
    {

        $response = $this->makeRequest("get_subscriptions");

        foreach ($response as $subscription) {
            if ($subscription['name'] == $package) {
                return $subscription['id'];
            }
        }

        throw ProvisionFunctionError::create("Subscription '$package' not found")
            ->withData(['response' => $response]);
    }
}
