<?php

declare(strict_types=1);

require_once __DIR__ . '/BackendKit.php';
require_once __DIR__ . '/Backend/Routes/http.php';
require_once __DIR__ . '/Backend/Routes/Gateway/shared.php';
require_once __DIR__ . '/Backend/Policies/common.php';

require_once __DIR__ . '/Backend/Modes/GatewayManaged/payment.php';
require_once __DIR__ . '/Backend/Modes/GatewayManaged/policy.php';
require_once __DIR__ . '/Backend/Modes/TenantDelegated/payment.php';
require_once __DIR__ . '/Backend/Modes/TenantDelegated/policy.php';
require_once __DIR__ . '/Backend/Modes/PublisherDelegated/payment.php';
require_once __DIR__ . '/Backend/Modes/PublisherDelegated/policy.php';
require_once __DIR__ . '/Backend/Modes/OwnerManaged/payment.php';
require_once __DIR__ . '/Backend/Modes/OwnerManaged/policy.php';
require_once __DIR__ . '/Backend/Modes/OwnerManaged/paymentPageApi.php';
require_once __DIR__ . '/Backend/Modes/index.php';

require_once __DIR__ . '/Backend/Routes/Gateway/hostApiCore.php';
require_once __DIR__ . '/Backend/Routes/Gateway/hostApiLifecycle.php';
require_once __DIR__ . '/Backend/Routes/Gateway/hostApiBridge.php';
require_once __DIR__ . '/Backend/Routes/Gateway/hostApi.php';
require_once __DIR__ . '/Backend/Routes/Gateway/payment.php';
require_once __DIR__ . '/Backend/Routes/Gateway/guard.php';
require_once __DIR__ . '/Backend/Routes/Gateway/subjectProfiles.php';
require_once __DIR__ . '/Backend/Routes/reference.php';
require_once __DIR__ . '/Backend/Routes/health.php';
require_once __DIR__ . '/Backend/Routes/embedSdk.php';
