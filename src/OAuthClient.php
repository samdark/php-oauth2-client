<?php

/**
 * Copyright (c) 2016, 2017 François Kooman <fkooman@tuxed.net>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace fkooman\OAuth\Client;

use DateTime;
use fkooman\OAuth\Client\Exception\OAuthException;
use fkooman\OAuth\Client\Exception\OAuthServerException;
use fkooman\OAuth\Client\Http\HttpClientInterface;
use fkooman\OAuth\Client\Http\Request;
use fkooman\OAuth\Client\Http\Response;
use ParagonIE\ConstantTime\Base64;

class OAuthClient
{
    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var \fkooman\OAuth\Client\Http\HttpClientInterface */
    private $httpClient;

    /** @var SessionInterface */
    private $session;

    /** @var RandomInterface */
    private $random;

    /** @var \DateTime */
    private $dateTime;

    /** @var Provider */
    private $provider = null;

    /** @var string */
    private $userId = null;

    /**
     * @param TokenStorageInterface    $tokenStorage
     * @param Http\HttpClientInterface $httpClient
     */
    public function __construct(TokenStorageInterface $tokenStorage, HttpClientInterface $httpClient)
    {
        $this->tokenStorage = $tokenStorage;
        $this->httpClient = $httpClient;

        $this->session = new Session();
        $this->random = new Random();
        $this->dateTime = new DateTime();
    }

    /**
     * @param Provider $provider
     */
    public function setProvider(Provider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * @param SessionInterface $session
     */
    public function setSession(SessionInterface $session)
    {
        $this->session = $session;
    }

    /**
     * @param RandomInterface $random
     */
    public function setRandom(RandomInterface $random)
    {
        $this->random = $random;
    }

    /**
     * @param DateTime $dateTime
     */
    public function setDateTime(DateTime $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    /**
     * @param string $userId
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    /**
     * Perform a GET request, convenience wrapper for ::send().
     *
     * @param string $requestScope
     * @param string $requestUri
     * @param array  $requestHeaders
     *
     * @return Http\Response|false
     */
    public function get($requestScope, $requestUri, array $requestHeaders = [])
    {
        return $this->send($requestScope, Request::get($requestUri, $requestHeaders));
    }

    /**
     * Perform a POST request, convenience wrapper for ::send().
     *
     * @param string $requestScope
     * @param string $requestUri
     * @param array  $postBody
     * @param array  $requestHeaders
     *
     * @return Http\Response|false
     */
    public function post($requestScope, $requestUri, array $postBody, array $requestHeaders = [])
    {
        return $this->send($requestScope, Request::post($requestUri, $postBody, $requestHeaders));
    }

    /**
     * Perform a HTTP request.
     *
     * @param string       $requestScope
     * @param Http\Request $request
     *
     * @return Response|false
     */
    public function send($requestScope, Request $request)
    {
        if (is_null($this->userId)) {
            throw new OAuthException('userId not set');
        }

        if (false === $accessToken = $this->getAccessToken($requestScope)) {
            return false;
        }

        if ($accessToken->isExpired($this->dateTime)) {
            // access_token is expired, try to refresh it
            if (is_null($accessToken->getRefreshToken())) {
                // we do not have a refresh_token, delete this access token, it
                // is useless now...
                $this->tokenStorage->deleteAccessToken($this->userId, $accessToken);

                return false;
            }

            // try to refresh the AccessToken
            if (false === $accessToken = $this->refreshAccessToken($accessToken)) {
                // didn't work
                return false;
            }
        }

        // add Authorization header to the request headers
        $request->setHeader('Authorization', sprintf('Bearer %s', $accessToken->getToken()));

        $response = $this->httpClient->send($request);
        if (401 === $response->getStatusCode()) {
            // the access_token was not accepted, but isn't expired, we assume
            // the user revoked it, also no need to try with refresh_token
            $this->tokenStorage->deleteAccessToken($this->userId, $accessToken);

            return false;
        }

        return $response;
    }

    /**
     * Obtain an authorization request URL to start the authorization process
     * at the OAuth provider.
     *
     * @param string $scope       the space separated scope tokens
     * @param string $redirectUri the URL registered at the OAuth provider, to
     *                            be redirected back to
     *
     * @return string the authorization request URL
     *
     * @see https://tools.ietf.org/html/rfc6749#section-3.3
     * @see https://tools.ietf.org/html/rfc6749#section-3.1.2
     */
    public function getAuthorizeUri($scope, $redirectUri)
    {
        if (is_null($this->userId)) {
            throw new OAuthException('userId not set');
        }

        $queryParameters = [
            'client_id' => $this->provider->getClientId(),
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $this->random->get(16),
            'response_type' => 'code',
        ];

        $authorizeUri = sprintf(
            '%s%s%s',
            $this->provider->getAuthorizationEndpoint(),
            false === strpos($this->provider->getAuthorizationEndpoint(), '?') ? '?' : '&',
            http_build_query($queryParameters, '&')
        );
        $this->session->set(
            '_oauth2_session',
            array_merge(
                $queryParameters,
                [
                    'user_id' => $this->userId,
                    'provider_id' => $this->provider->getProviderId(),
                ]
            )
        );

        return $authorizeUri;
    }

    /**
     * @param string $responseCode  the code passed to the "code"
     *                              query parameter on the callback URL
     * @param string $responseState the state passed to the "state"
     *                              query parameter on the callback URL
     */
    public function handleCallback($responseCode, $responseState)
    {
        if (is_null($this->userId)) {
            throw new OAuthException('userId not set');
        }

        $sessionData = $this->session->get('_oauth2_session');

        // delete the session, we don't want it to be used multiple times...
        $this->session->del('_oauth2_session');

        if (!hash_equals($sessionData['state'], $responseState)) {
            // the OAuth state from the initial request MUST be the same as the
            // state used by the response
            throw new OAuthException('invalid session (state)');
        }

        // session providerId MUST match current set Provider
        if ($sessionData['provider_id'] !== $this->provider->getProviderId()) {
            throw new OAuthException('invalid session (provider_id)');
        }

        // session userId MUST match current set userId
        if ($sessionData['user_id'] !== $this->userId) {
            throw new OAuthException('invalid session (user_id)');
        }

        // prepare access_token request
        $tokenRequestData = [
            'client_id' => $this->provider->getClientId(),
            'grant_type' => 'authorization_code',
            'code' => $responseCode,
            'redirect_uri' => $sessionData['redirect_uri'],
        ];

        $response = $this->httpClient->send(
            Request::post(
                $this->provider->getTokenEndpoint(),
                $tokenRequestData,
                [
                    'Authorization' => sprintf(
                        'Basic %s',
                        Base64::encode(
                            sprintf('%s:%s', $this->provider->getClientId(), $this->provider->getSecret())
                        )
                    ),
                ]
            )
        );

        if (400 === $response->getStatusCode()) {
            // check for "invalid_grant"
            $responseData = $response->json();
            if (!array_key_exists('error', $responseData) || 'invalid_grant' !== $responseData['error']) {
                // not an "invalid_grant", we can't deal with this here...
                throw new OAuthServerException($response);
            }

            throw new OAuthException('authorization_code was not accepted by the server');
        }

        if (!$response->isOkay()) {
            // if there is any other error, we can't deal with this here...
            throw new OAuthServerException($response);
        }

        $this->tokenStorage->storeAccessToken(
            $this->userId,
            AccessToken::fromCodeResponse(
                $this->provider,
                $this->dateTime,
                $response->json(),
                // in case server does not return a scope, we know it granted
                // our requested scope
                $sessionData['scope']
            )
        );
    }

    /**
     * @param AccessToken $accessToken
     *
     * @return AccessToken|false
     */
    private function refreshAccessToken(AccessToken $accessToken)
    {
        // prepare access_token request
        $tokenRequestData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $accessToken->getRefreshToken(),
            'scope' => $accessToken->getScope(),
        ];

        $response = $this->httpClient->send(
            Request::post(
                $this->provider->getTokenEndpoint(),
                $tokenRequestData,
                [
                    'Authorization' => sprintf(
                        'Basic %s',
                        Base64::encode(
                            sprintf('%s:%s', $this->provider->getClientId(), $this->provider->getSecret())
                        )
                    ),
                ]
            )
        );

        if (400 === $response->getStatusCode()) {
            // check for "invalid_grant"
            $responseData = $response->json();
            if (!array_key_exists('error', $responseData) || 'invalid_grant' !== $responseData['error']) {
                // not an "invalid_grant", we can't deal with this here...
                throw new OAuthServerException($response);
            }

            // delete the access_token, we assume the user revoked it
            $this->tokenStorage->deleteAccessToken($this->userId, $accessToken);

            return false;
        }

        if (!$response->isOkay()) {
            // if there is any other error, we can't deal with this here...
            throw new OAuthServerException($response);
        }

        $accessToken = AccessToken::fromRefreshResponse(
            $this->provider,
            $this->dateTime,
            $response->json(),
            // provide the old AccessToken to borrow some fields if the server
            // does not provide them on "refresh"
            $accessToken
        );

        // store the refreshed AccessToken
        $this->tokenStorage->storeAccessToken($this->userId, $accessToken);

        return $accessToken;
    }

    /**
     * Find an AccessToken in the list that matches this scope, bound to
     * providerId and userId.
     *
     * @param string $scope
     *
     * @return AccessToken|false
     */
    private function getAccessToken($scope)
    {
        $accessTokenList = $this->tokenStorage->getAccessTokenList($this->userId);
        foreach ($accessTokenList as $accessToken) {
            if ($this->provider->getProviderId() !== $accessToken->getProviderId()) {
                continue;
            }
            if ($scope !== $accessToken->getScope()) {
                continue;
            }

            return $accessToken;
        }

        return false;
    }
}
