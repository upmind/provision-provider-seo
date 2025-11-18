<?php

namespace Upmind\ProvisionProviders\Seo\Providers\RankingCoach\Helper;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
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
        } catch (Exception $ex) {
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
        string $domain
    ): void {
        @[$firstName, $lastName] = explode(' ', $customerName, 2);

        $body = [
            'email' => $customerEmail,
            'firstname' => $firstName,
            'lastname' => empty($lastName) ? $firstName : $lastName,
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
            if ($subscription['status'] === "active") {
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
            if ($subscription['name'] === $package) {
                return $subscription['id'];
            }
        }

        throw ProvisionFunctionError::create("Subscription '$package' not found")
            ->withData(['response' => $response]);
    }

    /**
     * @throws GuzzleException
     */
    private function makeRequest(string $endpoint, ?array $body = null): ?array
    {
        if ($body === null) {
            $body = [];
        }

        $body['api_username'] = $this->configuration->getUsername();
        $body['api_password'] = $this->configuration->getPassword();

        try {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            unset($body['api_username'], $body['api_password']);

            throw ProvisionFunctionError::create('Could not encode request body', $e)
                ->withData([
                    'request' => $body,
                ]);
        }

        $response = $this->client->request('POST', $endpoint, ['body' => $body]);
        $result = $response->getBody()->getContents();

        $response->getBody()->close();

        if ($result === '') {
            return null;
        }

        return $this->parseResponseData($result);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $result): array
    {
        try {
            $parsedResult = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $ex) {
            throw ProvisionFunctionError::create('Could not decode Provider API Response')
                ->withData([
                    'response' => $result,
                ]);
        }

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
    private function getResponseErrorMessage($responseData): ?string
    {
        if (!isset($responseData['status']) || $responseData['status'] !== 'error') {
            return null;
        }

        // Set Generic Response message if `message` is not set.
        $errorMessage = $responseData['message'] ?? 'Response Error.';

        $additionalInfo = [];

        if (isset($responseData['additional_infos'])) {
            foreach ($responseData['additional_infos'] as $value) {
                $additionalInfo[] = $value;
            }
        }

        // Return error message with additional info if available.
        return empty($additionalInfo) ? $errorMessage : $errorMessage . ' ' . implode(' ', $additionalInfo);
    }
}
