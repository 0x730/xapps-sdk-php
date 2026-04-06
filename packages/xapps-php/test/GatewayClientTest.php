<?php

declare(strict_types=1);

use Xapps\GatewayClient;
use Xapps\TestCurlShim;

return [
    [
        'name' => 'GatewayClient covers payment-session and hosted gateway helpers',
        'run' => static function (): void {
            $client = new GatewayClient(xappsPhpTestBaseUrl(), 'gateway-key');

            $created = $client->createPaymentSession([
                'paymentSessionId' => 'pay_fixture_1',
                'xappId' => 'xapp_demo',
                'toolName' => 'submit_demo',
                'amount' => '5.00',
                'currency' => 'USD',
                'returnUrl' => 'https://tenant.example.test/return',
            ]);
            xappsPhpAssertSame('pay_fixture_1', (string) ($created['session']['payment_session_id'] ?? ''));
            xappsPhpAssertContains(
                'https://pay.example.test/session/pay_fixture_1',
                (string) ($created['paymentPageUrl'] ?? ''),
                'payment page URL should be returned',
            );

            $fetched = $client->getPaymentSession('pay_fixture_1');
            xappsPhpAssertSame('pay_fixture_1', (string) ($fetched['session']['payment_session_id'] ?? ''));

            $hosted = $client->getGatewayPaymentSession([
                'paymentSessionId' => 'pay_fixture_1',
                'returnUrl' => 'https://tenant.example.test/return',
                'xappsResume' => 'resume_1',
            ]);
            xappsPhpAssertSame('pay_fixture_1', (string) ($hosted['session']['payment_session_id'] ?? ''));
            xappsPhpAssertSame('resume_1', (string) ($hosted['session']['xapps_resume'] ?? ''));

            $complete = $client->completeGatewayPayment([
                'paymentSessionId' => 'pay_fixture_1',
            ]);
            xappsPhpAssertSame('hosted_redirect', (string) ($complete['flow'] ?? ''));
            xappsPhpAssertContains(
                'https://checkout.example.test/gateway/pay_fixture_1',
                (string) ($complete['redirectUrl'] ?? ''),
                'gateway hosted complete should return a redirect URL',
            );

            $settled = $client->clientSettleGatewayPayment([
                'paymentSessionId' => 'pay_fixture_1',
                'status' => 'paid',
                'clientToken' => 'client_token_1',
            ]);
            xappsPhpAssertSame('immediate', (string) ($settled['flow'] ?? ''));
            xappsPhpAssertSame(
                'client_token_1',
                (string) ($settled['metadata']['client_token'] ?? ''),
                'client token should be preserved in metadata',
            );

            $resolved = $client->resolveSubject([
                'type' => 'user',
                'identifier' => [
                    'idType' => 'email',
                    'value' => 'demo@example.com',
                    'hint' => 'demo@example.com',
                ],
                'email' => 'demo@example.com',
            ]);
            xappsPhpAssertContains('subj_', (string) ($resolved['subjectId'] ?? ''), 'subject id should be returned');

            TestCurlShim::reset();
            $resolvedExternal = $client->resolveSubject([
                'type' => 'business_member',
                'identifier' => [
                    'idType' => 'tenant_member_id',
                    'value' => 'acct_123',
                    'hint' => 'Account 123',
                ],
                'email' => 'billing@example.com',
                'metadata' => ['company_ref' => 'company_a'],
                'linkId' => 'tenant-link-123',
            ]);
            xappsPhpAssertContains('subj_', (string) ($resolvedExternal['subjectId'] ?? ''), 'external subject id should be returned');
            $resolveRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('business_member', $resolveRequest['payload']['type'] ?? null, 'resolve type mismatch');
            xappsPhpAssertSame('tenant_member_id', $resolveRequest['payload']['identifier']['idType'] ?? null, 'resolve identifier type mismatch');
            xappsPhpAssertSame('acct_123', $resolveRequest['payload']['identifier']['value'] ?? null, 'resolve identifier value mismatch');
            xappsPhpAssertSame('Account 123', $resolveRequest['payload']['identifier']['hint'] ?? null, 'resolve identifier hint mismatch');
            xappsPhpAssertSame('billing@example.com', $resolveRequest['payload']['email'] ?? null, 'resolve email mismatch');
            xappsPhpAssertSame('company_a', $resolveRequest['payload']['metadata']['company_ref'] ?? null, 'resolve metadata mismatch');
            xappsPhpAssertSame('tenant-link-123', $resolveRequest['payload']['linkId'] ?? null, 'resolve linkId mismatch');

            TestCurlShim::reset();
            $catalog = $client->createCatalogSession([
                'origin' => 'http://localhost:3312',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
                'publishers' => ['', 'xplace', ' xplace ', '   '],
                'tags' => ['featured', '', ' featured '],
            ]);
            xappsPhpAssertContains('catalog_token_', (string) ($catalog['token'] ?? ''), 'catalog token should be returned');
            $catalogRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame(['xplace'], $catalogRequest['payload']['publishers'] ?? null, 'catalog publishers should be normalized');
            xappsPhpAssertSame(['featured'], $catalogRequest['payload']['tags'] ?? null, 'catalog tags should be normalized');

            $clientSelf = $client->getClientSelf();
            xappsPhpAssertSame('client_fixture_1', (string) ($clientSelf['client']['id'] ?? ''));
            xappsPhpAssertSame('auto_available', (string) ($clientSelf['client']['installation_policy']['mode'] ?? ''));
            xappsPhpAssertSame(
                'auto_update_compatible',
                (string) ($clientSelf['client']['installation_policy']['update_mode'] ?? ''),
            );

            TestCurlShim::reset();
            $widget = $client->createWidgetSession([
                'installationId' => 'inst_fixture_1',
                'widgetId' => 'widget_fixture_1',
                'origin' => 'http://localhost:3312',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertContains('widget_token_', (string) ($widget['token'] ?? ''), 'widget token should be returned');
            $widgetRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertTrue(
                !array_key_exists('resultPresentation', is_array($widgetRequest['payload'] ?? null) ? $widgetRequest['payload'] : []),
                'widget payload should omit null resultPresentation',
            );

            TestCurlShim::reset();
            $verified = $client->verifyBrowserWidgetContext([
                'hostOrigin' => 'http://localhost:3312',
                'installationId' => 'inst_fixture_1',
                'bindToolName' => 'submit_certificate_request_async',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertTrue(($verified['verified'] ?? false) === true, 'widget bootstrap verify should pass');
            xappsPhpAssertContains('req_latest_', (string) ($verified['latestRequestId'] ?? ''), 'latest request id should be returned');
            $verifyRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('/v1/requests/latest', $verifyRequest['path'] ?? null, 'verify path mismatch');
            xappsPhpAssertSame('http://localhost:3312', $verifyRequest['headers']['origin'] ?? null, 'verify origin mismatch');
            xappsPhpAssertSame('inst_fixture_1', $verifyRequest['query']['installationId'] ?? null, 'verify installation mismatch');
            xappsPhpAssertSame('submit_certificate_request_async', $verifyRequest['query']['toolName'] ?? null, 'verify tool mismatch');

            TestCurlShim::reset();
            $verifiedWithBootstrapTicket = $client->verifyBrowserWidgetContext([
                'hostOrigin' => 'http://localhost:3312',
                'installationId' => 'inst_fixture_1',
                'bindToolName' => 'submit_certificate_request_async',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
                'bootstrapTicket' => 'bst_fixture_123',
            ]);
            xappsPhpAssertTrue(($verifiedWithBootstrapTicket['verified'] ?? false) === true, 'widget bootstrap verify with bootstrapTicket should pass');
            $verifyTicketRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('Bearer bst_fixture_123', $verifyTicketRequest['headers']['authorization'] ?? null, 'verify bootstrap ticket auth mismatch');

            $installations = $client->listInstallations([
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertSame('inst_fixture_1', (string) ($installations['items'][0]['installationId'] ?? ''));

            $installed = $client->installXapp([
                'xappId' => 'xapp_demo',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
                'termsAccepted' => true,
            ]);
            xappsPhpAssertSame('inst_xapp_demo', (string) ($installed['installation']['installationId'] ?? ''));

            $updated = $client->updateInstallation([
                'installationId' => 'inst_fixture_1',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
                'termsAccepted' => true,
            ]);
            xappsPhpAssertSame('inst_fixture_1', (string) ($updated['installation']['installationId'] ?? ''));

            $uninstalled = $client->uninstallInstallation([
                'installationId' => 'inst_fixture_1',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertTrue(($uninstalled['ok'] ?? false) === true, 'uninstall should return ok');

            $catalog = $client->getXappMonetizationCatalog([
                'xappId' => 'xapp_demo',
                'subjectId' => 'subj_fixture_1',
                'installationId' => 'inst_fixture_1',
                'realmRef' => 'workspace_fixture_1',
                'locale' => 'ro',
                'country' => 'RO',
            ]);
            xappsPhpAssertSame('xapp_demo', (string) ($catalog['xapp_id'] ?? ''));
            $catalogRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('subj_fixture_1', $catalogRequest['query']['subject_id'] ?? null, 'catalog subject query mismatch');
            xappsPhpAssertSame('inst_fixture_1', $catalogRequest['query']['installation_id'] ?? null, 'catalog installation query mismatch');
            xappsPhpAssertSame('workspace_fixture_1', $catalogRequest['query']['realm_ref'] ?? null, 'catalog realm query mismatch');
            xappsPhpAssertSame('ro', $catalogRequest['query']['locale'] ?? null, 'catalog locale query mismatch');
            xappsPhpAssertSame('RO', $catalogRequest['query']['country'] ?? null, 'catalog country query mismatch');

            TestCurlShim::reset();
            $access = $client->getXappMonetizationAccess([
                'xappId' => 'xapp_demo',
                'subjectId' => 'subj_fixture_1',
            ]);
            xappsPhpAssertTrue(($access['access_projection']['has_current_access'] ?? false) === true, 'access projection should be returned');
            $accessRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('subj_fixture_1', $accessRequest['query']['subject_id'] ?? null, 'access scope query mismatch');

            $subscription = $client->getXappCurrentSubscription([
                'xappId' => 'xapp_demo',
                'installationId' => 'inst_fixture_1',
            ]);
            xappsPhpAssertSame('active', (string) ($subscription['current_subscription']['status'] ?? ''));

            TestCurlShim::reset();
            $entitlements = $client->listXappEntitlements([
                'xappId' => 'xapp_demo',
                'subjectId' => 'subj_fixture_1',
            ]);
            xappsPhpAssertSame('entitlement_fixture_1', (string) ($entitlements['items'][0]['id'] ?? ''));
            $entitlementsRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('subj_fixture_1', $entitlementsRequest['query']['subject_id'] ?? null, 'entitlements scope query mismatch');

            TestCurlShim::reset();
            $embedMonetization = $client->getEmbedMyXappMonetization([
                'xappId' => 'xapp_demo',
                'token' => 'widget_token_fixture',
                'installationId' => 'inst_fixture_1',
                'locale' => 'ro',
                'country' => 'RO',
                'realmRef' => 'workspace_fixture_1',
            ]);
            xappsPhpAssertSame('xapp_demo', (string) ($embedMonetization['xapp_id'] ?? ''));
            $embedMonetizationRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('widget_token_fixture', $embedMonetizationRequest['query']['token'] ?? null, 'embed monetization token query mismatch');
            xappsPhpAssertSame('inst_fixture_1', $embedMonetizationRequest['query']['installationId'] ?? null, 'embed monetization installation mismatch');

            TestCurlShim::reset();
            $embedHistory = $client->getEmbedMyXappMonetizationHistory([
                'xappId' => 'xapp_demo',
                'token' => 'widget_token_fixture',
                'installationId' => 'inst_fixture_1',
                'limit' => 8,
            ]);
            xappsPhpAssertSame('xapp_demo', (string) ($embedHistory['xapp_id'] ?? ''));
            xappsPhpAssertSame('intent_fixture_1', (string) ($embedHistory['history']['purchase_intents']['items'][0]['id'] ?? ''));
            $embedHistoryRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('widget_token_fixture', $embedHistoryRequest['query']['token'] ?? null, 'embed history token query mismatch');
            xappsPhpAssertSame('8', (string) ($embedHistoryRequest['query']['limit'] ?? ''), 'embed history limit mismatch');

            TestCurlShim::reset();
            $embedPreparedIntent = $client->prepareEmbedMyXappPurchaseIntent([
                'xappId' => 'xapp_demo',
                'token' => 'widget_token_fixture',
                'offeringId' => 'offering_fixture_1',
                'packageId' => 'pkg_fixture_1',
                'priceId' => 'price_fixture_1',
                'installationId' => 'inst_fixture_1',
                'locale' => 'ro',
                'country' => 'RO',
            ]);
            xappsPhpAssertSame('intent_fixture_1', (string) ($embedPreparedIntent['prepared_intent']['purchase_intent_id'] ?? ''));
            $embedPrepareRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('widget_token_fixture', $embedPrepareRequest['query']['token'] ?? null, 'embed prepare token mismatch');
            xappsPhpAssertSame('inst_fixture_1', $embedPrepareRequest['payload']['installation_id'] ?? null, 'embed prepare installation mismatch');

            TestCurlShim::reset();
            $embedPaymentSession = $client->createEmbedMyXappPurchasePaymentSession([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
                'token' => 'widget_token_fixture',
                'returnUrl' => 'https://tenant.example.test/return',
                'cancelUrl' => 'https://tenant.example.test/cancel',
                'installationId' => 'inst_fixture_1',
                'paymentGuardRef' => 'gateway_checkout_default',
                'issuer' => 'gateway',
                'scheme' => 'stripe',
            ]);
            xappsPhpAssertSame('pay_fixture_1', (string) ($embedPaymentSession['payment_session']['payment_session_id'] ?? ''));
            $embedPaymentSessionRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('widget_token_fixture', $embedPaymentSessionRequest['query']['token'] ?? null, 'embed payment-session token mismatch');
            xappsPhpAssertSame('gateway_checkout_default', $embedPaymentSessionRequest['payload']['payment_guard_ref'] ?? null, 'embed payment-session guard mismatch');

            TestCurlShim::reset();
            $embedFinalized = $client->finalizeEmbedMyXappPurchasePaymentSession([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
                'token' => 'widget_token_fixture',
            ]);
            xappsPhpAssertSame('settled', (string) ($embedFinalized['transaction']['status'] ?? ''), 'embed finalize transaction mismatch');
            $embedFinalizeRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('widget_token_fixture', $embedFinalizeRequest['query']['token'] ?? null, 'embed finalize token mismatch');

            TestCurlShim::reset();
            $widgetToolResult = $client->runWidgetToolRequest([
                'token' => 'widget_token_fixture',
                'installationId' => 'inst_fixture_1',
                'toolName' => 'complete_subject_profile',
                'payload' => ['source' => 'subject_self_profile'],
            ]);
            xappsPhpAssertSame('profile_fixture_1', (string) ($widgetToolResult['profile_id'] ?? ''), 'widget tool result mismatch');
            $widgetToolRequests = TestCurlShim::$requests;
            xappsPhpAssertSame('/v1/requests', $widgetToolRequests[0]['path'] ?? null, 'widget tool create path mismatch');
            xappsPhpAssertSame('Bearer widget_token_fixture', $widgetToolRequests[0]['headers']['authorization'] ?? null, 'widget tool auth mismatch');
            xappsPhpAssertSame('/v1/requests/req_widget_tool_1', $widgetToolRequests[1]['path'] ?? null, 'widget tool detail path mismatch');
            xappsPhpAssertSame('/v1/requests/req_widget_tool_1/response', $widgetToolRequests[2]['path'] ?? null, 'widget tool response path mismatch');

            TestCurlShim::reset();
            $preparedIntent = $client->prepareXappPurchaseIntent([
                'xappId' => 'xapp_demo',
                'offeringId' => 'offer_demo',
                'packageId' => 'pkg_demo',
                'priceId' => 'price_demo',
                'subjectId' => 'subj_fixture_1',
                'locale' => 'ro',
                'country' => 'RO',
            ]);
            xappsPhpAssertTrue(is_array($preparedIntent), 'prepared intent should return a payload array');
            $preparedIntentRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('ro', $preparedIntentRequest['payload']['locale'] ?? null, 'prepare locale payload mismatch');
            xappsPhpAssertSame('RO', $preparedIntentRequest['payload']['country'] ?? null, 'prepare country payload mismatch');

            TestCurlShim::reset();
            $walletAccounts = $client->listXappWalletAccounts([
                'xappId' => 'xapp_demo',
                'subjectId' => 'subj_fixture_1',
            ]);
            xappsPhpAssertSame('wallet_fixture_1', (string) ($walletAccounts['items'][0]['id'] ?? ''));
            $walletAccountsRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('subj_fixture_1', $walletAccountsRequest['query']['subject_id'] ?? null, 'wallet accounts scope query mismatch');

            TestCurlShim::reset();
            $walletLedger = $client->listXappWalletLedger([
                'xappId' => 'xapp_demo',
                'subjectId' => 'subj_fixture_1',
                'walletAccountId' => 'wallet_fixture_1',
                'paymentSessionId' => 'pay_fixture_1',
            ]);
            xappsPhpAssertSame('ledger_fixture_1', (string) ($walletLedger['items'][0]['id'] ?? ''));
            $walletLedgerRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('wallet_fixture_1', $walletLedgerRequest['query']['wallet_account_id'] ?? null, 'wallet ledger account filter mismatch');
            xappsPhpAssertSame('pay_fixture_1', $walletLedgerRequest['query']['payment_session_id'] ?? null, 'wallet ledger payment filter mismatch');

            TestCurlShim::reset();
            $consumedWallet = $client->consumeXappWalletCredits([
                'xappId' => 'xapp_demo',
                'walletAccountId' => 'wallet_fixture_1',
                'amount' => '25',
                'sourceRef' => 'feature:priority_support_call',
                'metadata' => [
                    'feature_key' => 'priority_support_call',
                    'surface' => 'creator-club',
                ],
            ]);
            xappsPhpAssertSame('475', (string) ($consumedWallet['wallet_account']['balance_remaining'] ?? ''));
            xappsPhpAssertSame('consume', (string) ($consumedWallet['wallet_ledger']['event_kind'] ?? ''));
            $consumeRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('/v1/xapps/xapp_demo/monetization/wallet-accounts/wallet_fixture_1/consume', $consumeRequest['path'] ?? null, 'wallet consume path mismatch');
            xappsPhpAssertSame('25', $consumeRequest['payload']['amount'] ?? null, 'wallet consume amount mismatch');
            xappsPhpAssertSame('feature:priority_support_call', $consumeRequest['payload']['source_ref'] ?? null, 'wallet consume source_ref mismatch');

            TestCurlShim::reset();
            $preparedIntent = $client->prepareXappPurchaseIntent([
                'xappId' => 'xapp_demo',
                'offeringId' => 'offering_fixture_1',
                'packageId' => 'pkg_fixture_1',
                'priceId' => 'price_fixture_1',
                'subjectId' => 'subj_fixture_1',
                'sourceKind' => 'owner_managed_external',
                'sourceRef' => 'test-playground',
                'paymentLane' => 'publisher_rendered_playground',
            ]);
            xappsPhpAssertSame('intent_fixture_1', (string) ($preparedIntent['prepared_intent']['purchase_intent_id'] ?? ''));
            $prepareRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('offering_fixture_1', $prepareRequest['payload']['offering_id'] ?? null, 'prepare intent offering mismatch');

            $loadedIntent = $client->getXappPurchaseIntent([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
            ]);
            xappsPhpAssertSame('intent_fixture_1', (string) ($loadedIntent['prepared_intent']['purchase_intent_id'] ?? ''));

            $createdTransaction = $client->createXappPurchaseTransaction([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
                'status' => 'verified',
                'paymentSessionId' => 'pay_fixture_1',
            ]);
            xappsPhpAssertSame('verified', (string) ($createdTransaction['transaction']['status'] ?? ''));

            $listedTransactions = $client->listXappPurchaseTransactions([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
            ]);
            xappsPhpAssertSame('txn_intent_fixture_1', (string) ($listedTransactions['items'][0]['id'] ?? ''));

            TestCurlShim::reset();
            $createdPaymentSession = $client->createXappPurchasePaymentSession([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
                'paymentGuardRef' => 'creator_club_gateway_managed_hosted',
                'returnUrl' => 'https://tenant.example.test/return',
                'issuer' => 'gateway',
                'scheme' => 'mock_manual',
                'metadata' => ['source' => 'php-test'],
            ]);
            xappsPhpAssertSame('pay_intent_fixture_1', (string) ($createdPaymentSession['payment_session']['payment_session_id'] ?? ''));
            $paymentSessionRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('creator_club_gateway_managed_hosted', $paymentSessionRequest['payload']['payment_guard_ref'] ?? null, 'payment session payment_guard_ref mismatch');

            $reconciled = $client->reconcileXappPurchasePaymentSession([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
            ]);
            xappsPhpAssertSame('verified', (string) ($reconciled['transaction']['status'] ?? ''));

            $finalized = $client->finalizeXappPurchasePaymentSession([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
            ]);
            xappsPhpAssertSame('verified', (string) ($finalized['transaction']['status'] ?? ''));
            xappsPhpAssertTrue(($finalized['access_projection']['has_current_access'] ?? false) === true, 'finalized access mismatch');

            $issued = $client->issueXappPurchaseAccess([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
            ]);
            xappsPhpAssertSame('paid', (string) ($issued['prepared_intent']['status'] ?? ''));

            $subscriptionReconcile = $client->reconcileXappSubscriptionContractPaymentSession([
                'xappId' => 'xapp_demo',
                'contractId' => 'sub_contract_1',
                'paymentSessionId' => 'pay_renewal_1',
            ]);
            xappsPhpAssertSame('active', (string) ($subscriptionReconcile['subscription_contract']['status'] ?? ''));

            $subscriptionCancel = $client->cancelXappSubscriptionContract([
                'xappId' => 'xapp_demo',
                'contractId' => 'sub_contract_1',
            ]);
            xappsPhpAssertSame('cancelled', (string) ($subscriptionCancel['subscription_contract']['status'] ?? ''));

            $subscriptionRefresh = $client->refreshXappSubscriptionContractState([
                'xappId' => 'xapp_demo',
                'contractId' => 'sub_contract_1',
            ]);
            xappsPhpAssertSame('past_due', (string) ($subscriptionRefresh['subscription_contract']['status'] ?? ''));
        },
    ],
];
