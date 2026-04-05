<?php

declare(strict_types=1);

namespace Xapps;

final class EmbedHostProxyService
{
    private GatewayClient $gatewayClient;
    private string $gatewayUrl;
    /** @var array<int,array{key:string,label:string}> */
    private array $hostModes;
    private int $tokenRefreshTtlSeconds;
    /** @var callable|null */
    private $signCallback;
    /** @var callable|null */
    private $vendorAssertionCallback;

    /** @param array<string,mixed> $options */
    public function __construct(GatewayClient $gatewayClient, array $options = [])
    {
        $this->gatewayClient = $gatewayClient;
        $this->gatewayUrl = trim((string) ($options['gatewayUrl'] ?? ''));
        $rawModes = is_array($options['hostModes'] ?? null) ? $options['hostModes'] : [];
        $this->hostModes = count($rawModes) > 0 ? array_values($rawModes) : [
            ['key' => 'single-panel', 'label' => 'Single Panel'],
            ['key' => 'split-panel', 'label' => 'Split Panel'],
        ];
        $ttl = (int) ($options['tokenRefreshTtlSeconds'] ?? 900);
        $this->tokenRefreshTtlSeconds = $ttl > 0 ? $ttl : 900;
        $this->signCallback = is_callable($options['sign'] ?? null) ? $options['sign'] : null;
        $this->vendorAssertionCallback = is_callable($options['vendorAssertion'] ?? null)
            ? $options['vendorAssertion']
            : null;
    }

    /** @return array{Cache-Control:string,Pragma:string,Expires:string} */
    public function getNoStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    /** @return array<string,mixed> */
    public function getHostConfig(): array
    {
        $result = [
            'ok' => true,
            'hostModes' => $this->hostModes,
        ];
        if ($this->gatewayUrl !== '') {
            $result['gatewayUrl'] = $this->gatewayUrl;
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array{subjectId:string,email:string,name:?string} */
    public function resolveSubject(array $input): array
    {
        $email = strtolower($this->requireTrimmedString($input['email'] ?? null, 'email'));
        $result = $this->gatewayClient->resolveSubject([
            'type' => 'user',
            'identifier' => [
                'idType' => 'email',
                'value' => $email,
                'hint' => $email,
            ],
            'email' => $email,
        ]);
        return [
            'subjectId' => (string) ($result['subjectId'] ?? ''),
            'email' => $email,
            'name' => $this->optionalString($input['name'] ?? null),
        ];
    }

    /** @param array<string,mixed> $input @return array{items:array<int,array<string,mixed>>} */
    public function listInstallations(array $input = []): array
    {
        return $this->gatewayClient->listInstallations([
            'subjectId' => $this->optionalString($input['subjectId'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @return array{installation:array<string,mixed>} */
    public function installXapp(array $input): array
    {
        return $this->gatewayClient->installXapp([
            'xappId' => $this->requireTrimmedString($input['xappId'] ?? null, 'xappId'),
            'subjectId' => $this->optionalString($input['subjectId'] ?? null),
            'termsAccepted' => $this->optionalBoolean($input['termsAccepted'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @return array{installation:array<string,mixed>} */
    public function updateInstallation(array $input): array
    {
        return $this->gatewayClient->updateInstallation([
            'installationId' => $this->requireTrimmedString($input['installationId'] ?? null, 'installationId'),
            'subjectId' => $this->optionalString($input['subjectId'] ?? null),
            'termsAccepted' => $this->optionalBoolean($input['termsAccepted'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function uninstallInstallation(array $input): array
    {
        return $this->gatewayClient->uninstallInstallation([
            'installationId' => $this->requireTrimmedString($input['installationId'] ?? null, 'installationId'),
            'subjectId' => $this->optionalString($input['subjectId'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @return array{token:string,embedUrl:string} */
    public function createCatalogSession(array $input): array
    {
        return $this->gatewayClient->createCatalogSession([
            'origin' => $this->requireTrimmedString($input['origin'] ?? null, 'origin'),
            'subjectId' => $this->optionalString($input['subjectId'] ?? null),
            'xappId' => $this->optionalString($input['xappId'] ?? null),
            'publishers' => is_array($input['publishers'] ?? null) ? $input['publishers'] : null,
            'tags' => is_array($input['tags'] ?? null) ? $input['tags'] : null,
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createWidgetSession(array $input): array
    {
        $result = $this->gatewayClient->createWidgetSession([
            'installationId' => $this->requireTrimmedString($input['installationId'] ?? null, 'installationId'),
            'widgetId' => $this->requireTrimmedString($input['widgetId'] ?? null, 'widgetId'),
            'origin' => $this->requireTrimmedString($input['origin'] ?? null, 'origin'),
            'xappId' => $this->optionalString($input['xappId'] ?? null),
            'subjectId' => $this->optionalString($input['subjectId'] ?? null),
            'requestId' => $this->optionalString($input['requestId'] ?? null),
            'hostReturnUrl' => $this->optionalString($input['hostReturnUrl'] ?? null),
            'resultPresentation' => $this->optionalString($input['resultPresentation'] ?? null),
            'guardUi' => is_array($input['guardUi'] ?? null) ? $input['guardUi'] : null,
        ]);
        $hostReturnUrl = $this->optionalString($input['hostReturnUrl'] ?? null);
        if ($hostReturnUrl !== null && isset($result['embedUrl']) && is_string($result['embedUrl'])) {
            $result['embedUrl'] = $this->rewriteEmbedUrlWithHostReturnUrl($result['embedUrl'], $hostReturnUrl);
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array{token:string,expires_in:int} */
    public function refreshWidgetToken(array $input): array
    {
        $result = $this->gatewayClient->createWidgetSession([
            'installationId' => $this->requireTrimmedString($input['installationId'] ?? null, 'installationId'),
            'widgetId' => $this->requireTrimmedString($input['widgetId'] ?? null, 'widgetId'),
            'origin' => $this->requireTrimmedString($input['origin'] ?? null, 'origin'),
            'subjectId' => $this->optionalString($input['subjectId'] ?? null),
            'hostReturnUrl' => $this->optionalString($input['hostReturnUrl'] ?? null),
        ]);
        return [
            'token' => $this->requireTrimmedString($result['token'] ?? null, 'token'),
            'expires_in' => $this->tokenRefreshTtlSeconds,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function getMyXappMonetization(array $input): array
    {
        return $this->gatewayClient->getEmbedMyXappMonetization([
            'xappId' => $this->requireTrimmedString($input['xappId'] ?? null, 'xappId'),
            'token' => $this->requireTrimmedString($input['token'] ?? null, 'token'),
            'installationId' => $this->optionalString($input['installationId'] ?? null),
            'locale' => $this->optionalString($input['locale'] ?? null),
            'country' => $this->optionalString($input['country'] ?? null),
            'realmRef' => $this->optionalString($input['realmRef'] ?? $input['realm_ref'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function getMyXappMonetizationHistory(array $input): array
    {
        return $this->gatewayClient->getEmbedMyXappMonetizationHistory([
            'xappId' => $this->requireTrimmedString($input['xappId'] ?? null, 'xappId'),
            'token' => $this->requireTrimmedString($input['token'] ?? null, 'token'),
            'limit' => isset($input['limit']) && is_numeric((string) $input['limit']) ? (int) $input['limit'] : null,
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function prepareMyXappPurchaseIntent(array $input): array
    {
        return $this->gatewayClient->prepareEmbedMyXappPurchaseIntent([
            'xappId' => $this->requireTrimmedString($input['xappId'] ?? null, 'xappId'),
            'token' => $this->requireTrimmedString($input['token'] ?? null, 'token'),
            'offeringId' => $this->optionalString($input['offeringId'] ?? $input['offering_id'] ?? null),
            'packageId' => $this->optionalString($input['packageId'] ?? $input['package_id'] ?? null),
            'priceId' => $this->optionalString($input['priceId'] ?? $input['price_id'] ?? null),
            'installationId' => $this->optionalString($input['installationId'] ?? $input['installation_id'] ?? null),
            'locale' => $this->optionalString($input['locale'] ?? null),
            'country' => $this->optionalString($input['country'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createMyXappPurchasePaymentSession(array $input): array
    {
        return $this->gatewayClient->createEmbedMyXappPurchasePaymentSession([
            'xappId' => $this->requireTrimmedString($input['xappId'] ?? null, 'xappId'),
            'intentId' => $this->requireTrimmedString($input['intentId'] ?? null, 'intentId'),
            'token' => $this->requireTrimmedString($input['token'] ?? null, 'token'),
            'returnUrl' => $this->optionalString($input['returnUrl'] ?? $input['return_url'] ?? null),
            'cancelUrl' => $this->optionalString($input['cancelUrl'] ?? $input['cancel_url'] ?? null),
            'xappsResume' => $this->optionalString($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
            'locale' => $this->optionalString($input['locale'] ?? null),
            'installationId' => $this->optionalString($input['installationId'] ?? $input['installation_id'] ?? null),
            'paymentGuardRef' => $this->optionalString($input['paymentGuardRef'] ?? $input['payment_guard_ref'] ?? null),
            'issuer' => $this->optionalString($input['issuer'] ?? null),
            'scheme' => $this->optionalString($input['scheme'] ?? null),
            'paymentScheme' => $this->optionalString($input['paymentScheme'] ?? $input['payment_scheme'] ?? null),
            'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : null,
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function finalizeMyXappPurchasePaymentSession(array $input): array
    {
        return $this->gatewayClient->finalizeEmbedMyXappPurchasePaymentSession([
            'xappId' => $this->requireTrimmedString($input['xappId'] ?? null, 'xappId'),
            'intentId' => $this->requireTrimmedString($input['intentId'] ?? null, 'intentId'),
            'token' => $this->requireTrimmedString($input['token'] ?? null, 'token'),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function runWidgetToolRequest(array $input): array
    {
        return $this->gatewayClient->runWidgetToolRequest([
            'token' => $this->requireTrimmedString($input['token'] ?? null, 'token'),
            'installationId' => $this->requireTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null, 'installationId'),
            'toolName' => $this->requireTrimmedString($input['toolName'] ?? $input['tool_name'] ?? null, 'toolName'),
            'payload' => is_array($input['payload'] ?? null) ? $input['payload'] : [],
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function bridgeSign(array $input): array
    {
        if (isset($input['envelope']) && is_array($input['envelope'])) {
            return ['ok' => true, 'envelope' => $input['envelope']];
        }
        if (is_callable($this->signCallback)) {
            return (array) call_user_func($this->signCallback, $input);
        }
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'Signing is not configured for this host. Provide a precomputed envelope or wire a signer.',
            501,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function bridgeVendorAssertion(array $input = []): array
    {
        if (is_callable($this->vendorAssertionCallback)) {
            return (array) call_user_func($this->vendorAssertionCallback, $input);
        }
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'Vendor assertion is not configured for this host. Add it only when publisher-linked bridge flows are required.',
            501,
        );
    }

    private function requireTrimmedString(mixed $value, string $field): string
    {
        $resolved = trim((string) ($value ?? ''));
        if ($resolved === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, $field . ' is required', 400);
        }
        return $resolved;
    }

    private function optionalString(mixed $value): ?string
    {
        $resolved = trim((string) ($value ?? ''));
        return $resolved !== '' ? $resolved : null;
    }

    private function optionalBoolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function rewriteEmbedUrlWithHostReturnUrl(string $embedUrl, string $hostReturnUrl): string
    {
        if ($hostReturnUrl === '') {
            return $embedUrl;
        }
        $parts = parse_url($embedUrl);
        if ($parts === false || !isset($parts['path'])) {
            return $embedUrl;
        }
        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        $query['xapps_host_return_url'] = $hostReturnUrl;
        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'];
        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $rebuilt .= '?' . $queryString;
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt !== '' ? $rebuilt : $embedUrl;
    }
}
