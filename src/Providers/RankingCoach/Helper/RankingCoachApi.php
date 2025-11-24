<?php

namespace Upmind\ProvisionProviders\Seo\Providers\RankingCoach\Helper;

use DateTimeImmutable;
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
     * @throws GuzzleException
     */
    public function createAccount(
        string $externalId,
        string $customerEmail,
        string $customerName,
        string $domain,
        string $planId
    ): void {
        $subscriptionId = $this->getSubscriptionId($planId);

        @[$firstName, $lastName] = explode(' ', $customerName, 2);

        $body = [
            'email' => $customerEmail,
            'firstname' => $firstName,
            'lastname' => empty($lastName) ? $firstName : $lastName,
            'external_id' => $externalId,
            'api_params_domain' => $domain
        ];

        // First create the user
        $this->makeRequest('update_user', $body);

        // Then activate with the requests plan/subscription.
        $this->activateUser($externalId, $subscriptionId);
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
     * @throws GuzzleException
     */
    public function unsuspend(string $userId): void
    {
        $this->activateUser($userId, null);
    }

    /**
     * @param string $userId
     * @param string $planId
     * @return void
     * @throws GuzzleException
     */
    public function changePackage(string $userId, string $planId): void
    {
        $this->validateSubscriptionId($planId);

        $account = $this->getAccountData($userId);

        $currentSubscription = null;

        foreach ($account['subscriptions'] as $subscription) {
            $createdAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $subscription['created']);

            // If $currentSubscription is not yet set, set it and move to next.
            if ($currentSubscription === null) {
                $currentSubscription = $subscription;
                $currentSubscription['created'] = $createdAt;

                continue;
            }

            // if current subscription is active and the subscription from the loop is not active, skip
            if ($currentSubscription['status'] === 'active' && $subscription['status'] !== 'active') {
                continue;
            }

            // If $currentSubscription is set, but not active, set the active from the loop
            if ($currentSubscription['status'] !== 'active' && $subscription['status'] === 'active') {
                $currentSubscription = $subscription;
                $currentSubscription['created'] = $createdAt;

                continue;
            }

            // If both are active as expected, get the latest one.
            if ($currentSubscription['status'] === 'active' && $subscription['status'] === 'active') {
                $currentSubscription = $currentSubscription['created'] > $createdAt
                    ? $currentSubscription
                    : $subscription;

                continue;
            }

            // Last case is both inactive, get the latest one.
            $currentSubscription = $currentSubscription['created'] > $createdAt
                ? $currentSubscription
                : $subscription;
        }

        // If no subscription set, activate account with provided plan
        if ($currentSubscription === null) {
            $this->activateUser($userId, $planId);

            return;
        }

        // Otherwise, if latest available subscription is not active, unsuspend (activate) first.
        if ($currentSubscription['status'] !== 'active') {
            $this->unsuspend($userId);
        }

        // And now continue to change package.
        if (!is_numeric($planId)) {
            $planId = (string) $this->getSubscriptionId($planId);
        }

        $body = [
            'external_id' => $userId,
            'subscription_id' => $currentSubscription['id'],
            'update_subscription_id' => $planId,
        ];

        $this->makeRequest('subscription_update', $body);
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
     * @throws GuzzleException
     */
    public function activateUser(string $userId, ?string $planId): void
    {
        $body = [
            'external_id' => $userId,
        ];

        // Now set Subscription ID if provided.
        if ($planId !== null) {
            $body['subscription_id'] = $planId;
        }

        if (isset($body['subscription_id']) && !is_numeric($body['subscription_id'])) {
            $body['subscription_id'] = (string) $this->getSubscriptionId($body['subscription_id']);
        }

        $this->makeRequest('activate_user', $body);
    }

    /**
     * Check if the provided package ID/Name exists in the account, otherwise error
     *
     * @throws GuzzleException
     */
    private function getSubscriptionId(string $package): int
    {
        $response = $this->makeRequest('get_subscriptions');

        foreach ($response as $subscription) {
            if ($this->isMatchingSubscription($package, $subscription)) {
                return $subscription['id'];
            }
        }

        throw ProvisionFunctionError::create('Subscription ' . $package . ' not found')
            ->withData(['response' => $response]);
    }

    private function isMatchingSubscription(string $package, array $subscription): bool
    {
        if (!isset($subscription['id'], $subscription['name'])) {
            return false;
        }

        if (!is_numeric($package)) {
            return $package === (string) $subscription['name'];
        }

        return $package === (string) $subscription['id'];
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
