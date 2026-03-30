<?php

class Shopify
{
    private string $codeStr = 'SH-';

    private string $shop;
    private string $accessToken;
    private array $headers;

    public function __construct()
    {
        $this->shop = getenv('SHOPIFY_SHOP');
        $this->accessToken = getenv('SHOPIFY_ACCESS_TOKEN');

        $this->headers = [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $this->accessToken
        ];
    }

    public function getName(): string
    {
        return 'Shopify';
    }

    private function graphql(string $query): array
    {
        $ch = curl_init("https://{$this->shop}/admin/api/2025-01/graphql.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $this->accessToken
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            curl_close($ch);
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error de conexión con Shopify'
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $rawErrors = $decoded['errors'] ?? null;
            $msg = match (true) {
                is_string($rawErrors)                      => $rawErrors,
                is_array($rawErrors) && isset($rawErrors[0]['message'])
                => $rawErrors[0]['message'],
                is_array($rawErrors)                       => implode(' | ', array_map(
                    fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
                    $rawErrors
                )),
                is_string($decoded['error'] ?? null)       => $decoded['error'],
                default                                    => 'HTTP ' . $httpCode
            };
            return [
                'status' => 'error',
                'code'   => $this->codeStr . $httpCode,
                'answer' => $msg
            ];
        }

        return $decoded;
    }

    # <--- PRODUCTS --->

    # Obtiene desde la API de Shopify los productos
    public function fetchRawProducts(array $params = []): array
    {
        $raw = [];
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {

            $after = $cursor ? ", after: \"$cursor\"" : "";

            $query = <<<GRAPHQL
            query {
                products(first: 50 $after) {
                    edges {
                        cursor
                        node {
                            id
                            title
                            description
                            category {
                                id
                                fullName
                            }
                            variants(first: 50) {
                                nodes {
                                    id
                                    title
                                    sku
                                    barcode
                                    price
                                    inventoryQuantity
                                    inventoryItem {
                                        requiresShipping
                                    }
                                    selectedOptions {
                                        name
                                        value
                                    }
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }
            GRAPHQL;

            $response = $this->graphql($query);

            if (($response['status'] ?? '') === 'error') {
                break;
            }

            $products = $response['data']['products'] ?? [];
            $edges = $products['edges'] ?? [];

            foreach ($edges as $edge) {

                $cursor = $edge['cursor'];
                $node = $edge['node'];

                $categoria = [];
                if (!empty($node['category']['fullName'])) {
                    $categoria = array_map('trim', explode('>', $node['category']['fullName']));
                }

                $variantsNodes = $node['variants']['nodes'] ?? [];
                $hasVariants = count($variantsNodes) > 1;

                foreach ($variantsNodes as $variant) {

                    $selectedOptions = $variant['selectedOptions'] ?? [];
                    $variantTitle = $variant['title'] ?? null;

                    if ($hasVariants) {
                        $variants = ($variantTitle === 'Default Title' || $variantTitle === null)
                            ? []
                            : $selectedOptions;
                    } else {
                        $variants = [];
                    }

                    $raw[] = [
                        'item_id'        => $hasVariants ? ($variant['id'] ?? '') : ($node['id'] ?? ''),
                        'padre_id'       => $hasVariants ? ($node['id'] ?? null) : null,
                        'item_nombre'    => $node['title'],
                        'variants'       => $variants,
                        'categoría'      => $categoria,
                        'descripcion'    => $node['description'] ?? null,
                        'stock_actual'   => $variant['inventoryQuantity'] ?? null,
                        'servicio'       => ($variant['inventoryItem']['requiresShipping'] ?? true) ? 0 : 1,
                        'precio'         => isset($variant['price']) ? (float)$variant['price'] : null,
                        'codigo_barra'   => $variant['barcode'] ?? null,
                        'codigo_interno' => $variant['sku'] ?? null,
                    ];
                }
            }

            $hasNextPage = $products['pageInfo']['hasNextPage'] ?? false;
        }

        return $raw;
    }

    # Crea un producto en Shopify
    public function createProduct(array $item): array
    {
        $rawVariants      = $item['variants'] ?? [];
        $requiresShipping = (int)($item['servicio'] ?? 0) === 0;
        $title            = addslashes($item['item_nombre'] ?? '');
        $description      = addslashes($item['descripcion'] ?? '');

        if (empty($rawVariants)) {

            $precio  = number_format((float)($item['precio'] ?? 0), 2, '.', '');
            $stock   = (int)($item['stock_actual'] ?? 0);
            $sku     = $item['codigo_interno'] ?? null;
            $barcode = $item['codigo_barra']   ?? null;

            $variantInput = $this->buildVariantInput([], $precio, $stock, $sku, $barcode, $requiresShipping);

            $mutation = <<<GRAPHQL
            mutation {
                productCreate(input: {
                    title: "{$title}"
                    descriptionHtml: "{$description}"
                    variants: [{$variantInput}]
                }) {
                    product {
                        id
                        variants(first: 1) { nodes { id } }
                    }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $response = $this->graphql($mutation);

            if (($response['status'] ?? '') === 'error') {
                return $response;
            }

            $userErrors = $response['data']['productCreate']['userErrors'] ?? [];

            if (!empty($userErrors)) {
                return [
                    'status' => 'error',
                    'code'   => $this->codeStr . '422',
                    'answer' => implode(' | ', array_column($userErrors, 'message'))
                ];
            }

            $product = $response['data']['productCreate']['product'] ?? null;

            if (!$product) {
                return [
                    'status' => 'error',
                    'code'   => $this->codeStr . '502',
                    'answer' => 'Shopify no devolvió el producto creado'
                ];
            }

            return [[
                'id_externo' => $product['variants']['nodes'][0]['id'] ?? null,
                'padre_id'   => null
            ]];
        }

        $optionsMap = [];
        foreach ($rawVariants as $v) {
            $name  = $v['name']  ?? null;
            $value = $v['value'] ?? null;
            if ($name && $value) {
                $optionsMap[$name][] = $value;
            }
        }

        $optionLines = [];
        foreach ($optionsMap as $optName => $values) {
            $valuesStr     = implode('", "', array_unique($values));
            $optionLines[] = "{name: \"{$optName}\", values: [\"{$valuesStr}\"]}";
        }
        $optionsGql = 'options: [' . implode(', ', $optionLines) . ']';

        $variantBlocks = [];
        foreach ($rawVariants as $v) {
            $precio  = number_format((float)($v['precio']        ?? $item['precio']        ?? 0), 2, '.', '');
            $stock   = (int)($v['stock_actual']  ?? $item['stock_actual']  ?? 0);
            $sku     = $v['codigo_interno'] ?? $item['codigo_interno'] ?? null;
            $barcode = $v['codigo_barra']   ?? $item['codigo_barra']   ?? null;

            $optValue = $v['value'] ?? null;
            $variantBlocks[] = $this->buildVariantInput(
                $optValue ? [$optValue] : [],
                $precio,
                $stock,
                $sku,
                $barcode,
                $requiresShipping
            );
        }
        $variantsGql = implode(",\n", $variantBlocks);

        $mutation = <<<GRAPHQL
        mutation {
            productCreate(input: {
                title: "{$title}"
                descriptionHtml: "{$description}"
                {$optionsGql}
                variants: [{$variantsGql}]
            }) {
                product {
                    id
                    variants(first: 50) { nodes { id } }
                }
                userErrors { field message }
            }
        }
        GRAPHQL;

        $response = $this->graphql($mutation);

        if (($response['status'] ?? '') === 'error') {
            return $response;
        }

        $userErrors = $response['data']['productCreate']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => implode(' | ', array_column($userErrors, 'message'))
            ];
        }

        $product = $response['data']['productCreate']['product'] ?? null;

        if (!$product) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'Shopify no devolvió el producto creado'
            ];
        }

        $productGid      = $product['id'];
        $variantNodes    = $product['variants']['nodes'] ?? [];
        $hasManyVariants = count($variantNodes) > 1;

        $result = [];
        foreach ($variantNodes as $node) {
            $result[] = [
                'id_externo' => $node['id'],
                'padre_id'   => $hasManyVariants ? $productGid : null
            ];
        }

        return $result;
    }

    private function buildVariantInput(
        array   $optionValues,
        string  $precio,
        int     $stock,
        ?string $sku,
        ?string $barcode,
        bool    $requiresShipping
    ): string {
        $optStr  = !empty($optionValues)
            ? 'options: ["' . implode('", "', $optionValues) . '"]'
            : '';
        $skuStr  = $sku     ? "sku: \"{$sku}\""        : '';
        $barStr  = $barcode ? "barcode: \"{$barcode}\"" : '';
        $shipStr = $requiresShipping ? 'true' : 'false';

        return "{
            {$optStr}
            price: \"{$precio}\"
            {$skuStr}
            {$barStr}
            inventoryQuantities: [{
                availableQuantity: {$stock}
                locationId: \"gid:#shopify/Location/1\"
            }]
            inventoryItem: { requiresShipping: {$shipStr} }
        }";
    }

    # <--- ORDERS --->

    # Obtiene las ordenes desde Shopify
    public function fetchRawOrders(): array
    {
        $cursor      = null;
        $hasNextPage = true;
        $normalized  = [];

        while ($hasNextPage) {
            $after = $cursor ? ", after: \"{$cursor}\"" : "";

            $query = <<<GRAPHQL
            query {
                orders(first: 50{$after}, query: "status:any") {
                    edges {
                        cursor
                        node {
                            id
                            name

                            createdAt
                            processedAt
                            closedAt
                            cancelledAt

                            customer {
                                id
                            }

                            shippingAddress {
                                address1
                                city
                                province
                                country
                                zip
                            }

                            currencyCode
                            note

                            displayFinancialStatus
                            fullyPaid
                            unpaid

                            totalPriceSet {
                                shopMoney { amount currencyCode }
                            }
                            totalTaxSet {
                                shopMoney { amount currencyCode }
                            }
                            totalReceivedSet {
                                shopMoney { amount currencyCode }
                            }
                            totalOutstandingSet {
                                shopMoney { amount currencyCode }
                            }

                            taxLines {
                                title
                                rate
                                priceSet {
                                    shopMoney { amount currencyCode }
                                }
                            }

                            paymentTerms {
                                paymentTermsName
                                paymentTermsType
                                dueInDays
                            }

                            displayFulfillmentStatus

                            paymentGatewayNames
                            transactions(first: 5) {
                                gateway
                                formattedGateway
                                paymentDetails {
                                    ... on CardPaymentDetails {
                                        paymentMethodName
                                    }
                                    ... on ShopPayInstallmentsPaymentDetails {
                                        paymentMethodName
                                    }
                                }
                            }

                            lineItems(first: 50) {
                                edges {
                                    node {
                                        id
                                        title
                                        name
                                        quantity
                                        requiresShipping
                                        product {
                                            id
                                            hasOnlyDefaultVariant
                                        }
                                        variant {
                                            id
                                            barcode
                                            sku
                                        }
                                        originalUnitPriceSet {
                                            shopMoney { amount currencyCode }
                                        }
                                        taxLines {
                                            title
                                            rate
                                            priceSet {
                                                shopMoney { amount currencyCode }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }
            GRAPHQL;

            $response = $this->graphql($query);

            if (($response['status'] ?? '') === 'error') {
                break;
            }

            $ordersData  = $response['data']['orders'] ?? [];
            $edges       = $ordersData['edges'] ?? [];
            $hasNextPage = $ordersData['pageInfo']['hasNextPage'] ?? false;

            foreach ($edges as $edge) {
                $cursor = $edge['cursor'];
                $node   = $edge['node'];

                $idExterno     = $node['id'];
                $customerExtId = $node['customer']['id'] ?? '';

                $partes = [];
                foreach ($node['lineItems']['edges'] ?? [] as $lineEdge) {
                    $line = $lineEdge['node'];

                    $productId             = $line['product']['id'] ?? null;
                    $hasOnlyDefaultVariant = $line['product']['hasOnlyDefaultVariant'] ?? null;
                    $variantId             = $line['variant']['id'] ?? null;

                    if ($hasOnlyDefaultVariant === true) {
                        $itemExtId = $productId ?: '';
                    } elseif ($hasOnlyDefaultVariant === false && $variantId) {
                        $itemExtId = $variantId;
                    } else {
                        $itemExtId = $variantId ?: ($productId ?: '');
                    }

                    $unitPrice      = $line['originalUnitPriceSet']['shopMoney']['amount'] ?? 0;
                    $variantSku     = $line['variant']['sku']     ?? null;
                    $variantBarcode = $line['variant']['barcode'] ?? null;

                    $partes[] = [
                        'item_id'        => $itemExtId,
                        'item_aizu_id'   => null,
                        'item_nombre'    => (string)($line['name'] ?? $line['title'] ?? ''),
                        'cant'           => (int)($line['quantity'] ?? 1),
                        'precio'         => (float)$unitPrice,
                        'codigo_barra'   => $variantBarcode,
                        'codigo_interno' => $variantSku,
                        'stock_actual'   => null,
                        'descripcion'    => null,
                        'servicio'       => ($line['requiresShipping'] === false) ? 1 : 0,
                    ];
                }

                $gateways     = $node['paymentGatewayNames'] ?? [];
                $transactions = $node['transactions'] ?? [];
                $formaPago    = null;

                foreach ($transactions as $tx) {
                    $gateway          = $tx['gateway'] ?? '';
                    $formattedGateway = strtolower($tx['formattedGateway'] ?? '');
                    $paymentDetails   = $tx['paymentDetails'] ?? null;

                    if (isset($paymentDetails['paymentMethodName'])) {
                        $methodName = strtolower($paymentDetails['paymentMethodName']);
                        if ($methodName === 'card') { $formaPago = 'TARJETA'; break; }
                        if (strpos($methodName, 'shop') !== false) { $formaPago = 'SHOP_PAY'; break; }
                    }

                    if (
                        strpos($formattedGateway, 'cash')      !== false ||
                        strpos($formattedGateway, 'efectivo')  !== false ||
                        strpos($formattedGateway, 'manual')    !== false
                    ) { $formaPago = 'EFECTIVO'; break; }

                    if (
                        strpos($formattedGateway, 'bank')         !== false ||
                        strpos($formattedGateway, 'transfer')     !== false ||
                        strpos($formattedGateway, 'transferencia')!== false
                    ) { $formaPago = 'TRANSFERENCIA'; break; }

                    if (!$formaPago && $gateway) {
                        $formaPago = 'EFECTIVO';
                    }
                }

                if (!$formaPago && !empty($gateways)) {
                    $formaPago = $gateways[0];
                }

                $paymentTerms = $node['paymentTerms'] ?? null;
                $metodoPago   = 'PUE';

                if ($paymentTerms) {
                    $dueInDays        = $paymentTerms['dueInDays'] ?? null;
                    $paymentTermsName = strtolower($paymentTerms['paymentTermsName'] ?? '');
                    if (
                        (is_int($dueInDays) && $dueInDays > 0) ||
                        strpos($paymentTermsName, 'net') !== false
                    ) {
                        $metodoPago = 'PPD';
                    }
                }

                $displayFinancialStatus = strtoupper($node['displayFinancialStatus'] ?? '');
                $fullyPaid              = (bool)($node['fullyPaid'] ?? false);
                $unpaid                 = (bool)($node['unpaid']    ?? false);

                $totalPedido    = isset($node['totalPriceSet']['shopMoney']['amount'])
                    ? (float)$node['totalPriceSet']['shopMoney']['amount'] : null;
                $totalImpuestos = isset($node['totalTaxSet']['shopMoney']['amount'])
                    ? (float)$node['totalTaxSet']['shopMoney']['amount'] : null;
                $totalRecibido  = isset($node['totalReceivedSet']['shopMoney']['amount'])
                    ? (float)$node['totalReceivedSet']['shopMoney']['amount'] : 0.0;
                $totalPendiente = isset($node['totalOutstandingSet']['shopMoney']['amount'])
                    ? (float)$node['totalOutstandingSet']['shopMoney']['amount'] : 0.0;

                $anticipo = ($totalRecibido > 0 && $totalPendiente > 0) ? $totalRecibido : 0.0;

                $ivaTotal      = $totalImpuestos;
                $ivaPorcentaje = null;

                foreach ($node['taxLines'] ?? [] as $taxLine) {
                    if (strpos(strtolower($taxLine['title'] ?? ''), 'iva') !== false) {
                        $ivaPorcentaje = isset($taxLine['rate']) ? (float)$taxLine['rate'] * 100.0 : null;
                        break;
                    }
                }

                $fulfillmentStatus = strtoupper($node['displayFulfillmentStatus'] ?? '');
                $cancelledAt       = $node['cancelledAt'] ?? null;
                $estaCancelado     = !empty($cancelledAt);

                if ($estaCancelado) {
                    $estadoPedido = 'CANCELADO';
                } elseif (in_array($fulfillmentStatus, ['FULFILLED', 'SHIPPED'], true)) {
                    $estadoPedido = 'ENTREGADO';
                } elseif ($fulfillmentStatus === 'PARTIAL') {
                    $estadoPedido = 'PARCIALMENTE_ENTREGADO';
                } else {
                    $estadoPedido = 'PENDIENTE';
                }

                $pagado = in_array($displayFinancialStatus, ['PAID', 'PARTIALLY_PAID', 'PARTIALLY_REFUNDED'], true);

                $pago = [
                    'cuenta_receptora_id'           => null,
                    'cuenta_receptora_clabe'        => null,
                    'cuenta_receptora_beneficiario' => null,
                    'comision_aizu_id'              => null,
                    'comision_nombre'               => null,
                    'comision_porcentaje'           => null,
                    'comision_importe'              => null,
                    'cuenta_emisora'                => null,
                    'forma_pago'                    => $formaPago,
                    'metodo_pago'                   => $metodoPago,
                    'moneda'                        => $node['currencyCode'] ?? 'MXN',
                    'tasa_moneda'                   => 1.0,
                ];

                $vendedor = [
                    'aizu_id' => null,
                    'user'    => 'Shopify',
                    'nombre'  => 'Shopify',
                ];

                $createdAt   = $node['createdAt']   ?? null;
                $processedAt = $node['processedAt'] ?? null;
                $closedAt    = $node['closedAt']    ?? null;

                $shippingNode = $node['shippingAddress'] ?? null;
                $shippingAddress = $shippingNode ? [
                    'calle'  => $shippingNode['address1'] ?? null,
                    'cp'     => $shippingNode['zip']      ?? null,
                    'ciudad' => $shippingNode['city']     ?? null,
                    'estado' => $shippingNode['province'] ?? null,
                    'pais'   => $shippingNode['country']  ?? null,
                ] : null;

                $normalized[] = [
                    'id_externo'       => $idExterno,
                    'noPedido'          => isset($node['name'])
                        ? (int) filter_var($node['name'], FILTER_SANITIZE_NUMBER_INT)
                        : null,
                    'customer_ext_id'  => $customerExtId,
                    'shipping_address' => $shippingAddress,
                    'vendedor'         => $vendedor,
                    'pago'             => $pago,
                    'partes'           => $partes,
                    'total'            => $totalPedido,
                    'notas'            => $node['note'] ?? null,
                    'fecha_pedido'     => $createdAt,
                    'fecha_inicio'     => $processedAt ?? $createdAt,
                    'fecha_final'      => $closedAt ?? $cancelledAt ?? null,
                    'anticipo'         => $anticipo,
                    'iva_total'        => $ivaTotal,
                    'iva_porcentaje'   => $ivaPorcentaje,
                    'pagado'           => $pagado,
                    'pagado_completo'  => $fullyPaid,
                    'estado_pedido'    => $estadoPedido,
                    'facturado'        => null,
                ];
            }
        }

        return $normalized;
    }

    # <--- CUSTOMERS --->

    # Obtiene los clientes desde la API de Shopify
    public function fetchRawCustomers(array $params = []): array
    {
        $cursor      = null;
        $hasNextPage = true;
        $raw         = [];

        while ($hasNextPage) {

            $after = $cursor ? ", after: \"{$cursor}\"" : '';
            $query = <<<GRAPHQL
            query {
              customers(first: 100 {$after}) {
                edges {
                  cursor
                  node {
                    id
                    displayName
                    createdAt
                    defaultEmailAddress { emailAddress }
                    defaultPhoneNumber  { phoneNumber  }
                    email
                    phone
                    note
                    defaultAddress { id }
                    addressesV2(first: 50) {
                      edges {
                        node {
                          id
                          address1
                          address2
                          city
                          province
                          country
                          zip
                          latitude
                          longitude
                        }
                      }
                    }
                  }
                }
                pageInfo { hasNextPage }
              }
            }
            GRAPHQL;

            $response    = $this->graphql($query);

            if (($response['status'] ?? '') === 'error') {
                break;
            }

            $edges       = $response['data']['customers']['edges'] ?? [];
            $hasNextPage = $response['data']['customers']['pageInfo']['hasNextPage'] ?? false;
            $cursor      = null;

            foreach ($edges as $edge) {
                $node             = $edge['node'];
                $cursor           = $edge['cursor'];
                $defaultAddressId = $node['defaultAddress']['id'] ?? null;

                $direcciones = [];
                foreach ($node['addressesV2']['edges'] ?? [] as $addrEdge) {
                    $addr          = $addrEdge['node'];
                    $direcciones[] = [
                        'id_externo'     => $addr['id'],
                        'calle'          => $addr['address1']  ?? null,
                        'no_ext'         => null,
                        'no_int'         => null,
                        'colonia'        => null,
                        'cp'             => $addr['zip']       ?? null,
                        'municipio'      => $addr['city']      ?? null,
                        'estado'         => $addr['province']  ?? null,
                        'ciudad'         => $addr['city']      ?? null,
                        'pais'           => $addr['country']   ?? null,
                        'referencias'    => $addr['address2']  ?? null,
                        'gps'            => [
                            'latitud'  => $addr['latitude']  ?? null,
                            'longitud' => $addr['longitude'] ?? null,
                        ],
                        'predeterminada' => ($addr['id'] === $defaultAddressId),
                    ];
                }

                $phoneData = Utils::extractPhoneData($node['phone'] ?? null);

                $raw[] = [
                    'id'          => $node['id'],
                    'origen'      => 'Shopify',
                    'nombre'      => $node['displayName'] ?? null,
                    'email'       => $node['defaultEmailAddress']['emailAddress'] ?? $node['email'] ?? null,
                    'telefono'    => $phoneData['numero'],
                    'movil'       => $node['defaultPhoneNumber']['phoneNumber'] ?? null,
                    'lada'        => $phoneData['lada'],
                    'notas'       => $node['note'] ?? null,
                    'rfc'         => null,
                    'prospecto'   => 0,
                    'created_at'  => $node['createdAt'] ?? null,
                    'direcciones' => $direcciones,
                ];
            }
        }

        return $raw;
    }

    # Crea un cliente en Shopify
    public function createCustomer(array $customer): array
    {
        $nombre = trim($customer['nombre'] ?? '');
        $email  = $customer['email'] ?? null;
        $notas  = addslashes($customer['notas'] ?? '');

        # separar nombre
        $firstName = $nombre;
        $lastName  = '';

        if ($nombre) {
            $parts = preg_split('/\s+/', $nombre);
            if (count($parts) > 1) {
                $firstName = array_shift($parts);
                $lastName  = implode(' ', $parts);
            }
        }

        # normalizar telefono
        $phoneData = Utils::extractPhoneData($customer['movil'] ?? $customer['telefono'] ?? null);
        $phone = ($phoneData['lada'] ?? '') . ($phoneData['numero'] ?? '');

        # dirección (solo la primera)
        $direccion = $customer['default_address'] ?? null;
        $addressInput = '';

        if (!empty($direccion)) {

            $calle    = addslashes($direccion['calle'] ?? '');
            $ext      = $direccion['no_ext'] ?? '';
            $int      = $direccion['no_int'] ?? '';

            $address1 = trim("{$calle} {$ext}");
            $address2 = $int ? addslashes($int) : '';

            $ciudad   = addslashes($direccion['ciudad'] ?? '');
            $estado   = addslashes($direccion['estado'] ?? '');
            $pais     = addslashes(Utils::normalizeCountry($direccion['pais'] ?? 'Mexico'));
            $zip      = addslashes($direccion['cp'] ?? '');

            $addressInput = <<<ADDR
            addresses: [{
                address1: "{$address1}"
                address2: "{$address2}"
                city: "{$ciudad}"
                province: "{$estado}"
                country: "{$pais}"
                zip: "{$zip}"
                phone: "{$phone}"
            }]
            ADDR;
        }

        $firstName = addslashes($firstName);
        $lastName  = addslashes($lastName);
        $email     = addslashes($email);
        $phone     = addslashes($phone);

        $mutation = <<<GRAPHQL
        mutation {
            customerCreate(input: {
                firstName: "{$firstName}"
                lastName: "{$lastName}"
                email: "{$email}"
                phone: "{$phone}"
                note: "{$notas}"
                {$addressInput}
            }) {
                customer {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $response = $this->graphql($mutation);

        if (($response['status'] ?? '') === 'error') {
            return $response;
        }

        $userErrors = $response['data']['customerCreate']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => implode(' | ', array_column($userErrors, 'message'))
            ];
        }

        $customerNode = $response['data']['customerCreate']['customer'] ?? null;

        if (!$customerNode) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'Shopify no devolvió el cliente creado'
            ];
        }

        return [[
            'id_externo' => $customerNode['id'],
            'padre_id'   => null
        ]];
    }

    # Actualiza un cliente en Shopify
    public function updateCustomer(string $idExterno, array $customer): array
    {
        $fields = [];

        if (array_key_exists('nombre', $customer)) {
            $nombre    = trim($customer['nombre'] ?? '');
            $parts     = preg_split('/\s+/', $nombre);
            $firstName = addslashes(array_shift($parts));
            $lastName  = addslashes(implode(' ', $parts));
            $fields[]  = "firstName: \"{$firstName}\"";
            $fields[]  = "lastName: \"{$lastName}\"";
        }

        if (array_key_exists('email', $customer)) {
            $email    = addslashes($customer['email'] ?? '');
            $fields[] = "email: \"{$email}\"";
        }

        if (array_key_exists('movil', $customer) || array_key_exists('telefono', $customer)) {
            $phoneData = Utils::extractPhoneData($customer['movil'] ?? $customer['telefono'] ?? null);
            $phone     = addslashes(($phoneData['lada'] ?? '') . ($phoneData['numero'] ?? ''));
            if ($phone) $fields[] = "phone: \"{$phone}\"";
        }

        if (array_key_exists('notas', $customer)) {
            $notas    = addslashes($customer['notas'] ?? '');
            $fields[] = "note: \"{$notas}\"";
        }

        if (empty($fields)) {
            return ['status' => 'ok'];
        }

        $fieldsGql = implode("\n", $fields);
        $id        = addslashes($idExterno);

        $mutation = <<<GRAPHQL
        mutation {
            customerUpdate(input: {
                id: "{$id}"
                {$fieldsGql}
            }) {
                customer { id }
                userErrors { field message }
            }
        }
        GRAPHQL;

        $response = $this->graphql($mutation);

        if (($response['status'] ?? '') === 'error') {
            return $response;
        }

        $userErrors = $response['data']['customerUpdate']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => implode(' | ', array_column($userErrors, 'message'))
            ];
        }

        return ['status' => 'ok'];
    }

    # Obtiene las direcciones de un cliente específico en Shopify
    public function fetchCustomerAddresses(string $idExterno): array
    {
        $id    = addslashes($idExterno);
        $query = <<<GRAPHQL
        query {
            customer(id: "{$id}") {
                defaultAddress { id }
                addressesV2(first: 50) {
                    edges {
                        node {
                            id
                            address1
                            address2
                            city
                            province
                            country
                            zip
                            latitude
                            longitude
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $response = $this->graphql($query);

        if (($response['status'] ?? '') === 'error') {
            return [];
        }

        $customerNode     = $response['data']['customer'] ?? null;
        if (!$customerNode) return [];

        $defaultAddressId = $customerNode['defaultAddress']['id'] ?? null;
        $direcciones      = [];

        foreach ($customerNode['addressesV2']['edges'] ?? [] as $addrEdge) {
            $addr          = $addrEdge['node'];
            $direcciones[] = [
                'calle'          => $addr['address1']  ?? null,
                'no_ext'         => null,
                'no_int'         => null,
                'colonia'        => null,
                'cp'             => $addr['zip']       ?? null,
                'municipio'      => $addr['city']      ?? null,
                'estado'         => $addr['province']  ?? null,
                'ciudad'         => $addr['city']      ?? null,
                'pais'           => $addr['country']   ?? null,
                'referencias'    => $addr['address2']  ?? null,
                'gps'            => ['latitud' => null, 'longitud' => null],
                'predeterminada' => ($addr['id'] === $defaultAddressId),
            ];
        }

        return $direcciones;
    }

    public function supportsMultipleAddresses(): bool
    {
        return true;
    }

    # Agrega la dirección recibida como nueva en Shopify y la establece como predeterminada
    public function syncDefaultAddress(string $customerId, array $address): array
    {
        # Extraer el ID numérico del GID (gid:#shopify/Customer/123456)
        preg_match('/(\d+)$/', $customerId, $matches);
        $numericId = $matches[1] ?? null;

        if (!$numericId) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'No se pudo extraer el ID numérico del cliente de Shopify'
            ];
        }

        $address1 = trim(($address['calle'] ?? '') . ' ' . ($address['no_ext'] ?? ''));
        $address2 = $address['no_int'] ?? '';

        $body = json_encode([
            'address' => [
                'address1' => $address1,
                'address2' => $address2,
                'city'     => $address['ciudad']  ?? '',
                'province' => $address['estado']  ?? '',
                'country'  => Utils::normalizeCountry($address['pais'] ?? 'Mexico'),
                'zip'      => $address['cp']       ?? '',
            ]
        ]);

        # Crear la nueva dirección
        $ch = curl_init("https:#{$this->shop}/admin/api/2025-01/customers/{$numericId}/addresses.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $decoded  = json_decode($response ?? '', true) ?? [];
            $errors   = $decoded['errors'] ?? null;
            $msg      = match (true) {
                is_string($errors)                => $errors,
                is_array($errors)                 => implode(' | ', array_map(
                    fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
                    $errors
                )),
                default                           => 'HTTP ' . $httpCode
            };
            return [
                'status' => 'error',
                'code'   => $this->codeStr . $httpCode,
                'answer' => $msg
            ];
        }

        $decoded   = json_decode($response, true) ?? [];
        $addressId = $decoded['customer_address']['id'] ?? null;

        if (!$addressId) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'Shopify no devolvió el ID de la dirección creada'
            ];
        }

        # Establecer como predeterminada
        $ch = curl_init("https:#{$this->shop}/admin/api/2025-01/customers/{$numericId}/addresses/{$addressId}/default.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        $response2 = curl_exec($ch);
        $httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response2 === false || $httpCode2 >= 400) {
            $decoded2 = json_decode($response2 ?? '', true) ?? [];
            $errors2  = $decoded2['errors'] ?? null;
            $msg2     = match (true) {
                is_string($errors2) => $errors2,
                is_array($errors2)  => implode(' | ', array_map(
                    fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
                    $errors2
                )),
                default             => 'HTTP ' . $httpCode2
            };
            return [
                'status' => 'error',
                'code'   => $this->codeStr . $httpCode2,
                'answer' => 'Dirección creada pero no se pudo establecer como predeterminada: ' . $msg2
            ];
        }

        return ['status' => 'ok'];
    }

    # Agrega una dirección al cliente en Shopify sin marcarla como predeterminada
    public function addAddress(string $customerId, array $address): array
    {
        preg_match('/(\d+)$/', $customerId, $matches);
        $numericId = $matches[1] ?? null;

        if (!$numericId) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => 'No se pudo extraer el ID numérico del cliente de Shopify'
            ];
        }

        $address1 = trim(($address['calle'] ?? '') . ' ' . ($address['no_ext'] ?? ''));
        $address2 = $address['no_int'] ?? '';

        $body = json_encode([
            'address' => [
                'address1' => $address1,
                'address2' => $address2,
                'city'     => $address['ciudad']  ?? '',
                'province' => $address['estado']  ?? '',
                'country'  => Utils::normalizeCountry($address['pais'] ?? 'Mexico'),
                'zip'      => $address['cp']       ?? '',
            ]
        ]);

        $ch = curl_init("https:#{$this->shop}/admin/api/2025-01/customers/{$numericId}/addresses.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $decoded = json_decode($response ?? '', true) ?? [];
            $errors  = $decoded['errors'] ?? null;
            $msg     = match (true) {
                is_string($errors) => $errors,
                is_array($errors)  => implode(' | ', array_map(
                    fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
                    $errors
                )),
                default            => 'HTTP ' . $httpCode
            };
            return [
                'status' => 'error',
                'code'   => $this->codeStr . $httpCode,
                'answer' => $msg
            ];
        }

        return ['status' => 'ok'];
    }

    # Elimina un cliente en Shopify
    public function deleteCustomer(string $idExterno): array
    {
        $id = addslashes($idExterno);

        $mutation = <<<GRAPHQL
        mutation {
            customerDelete(input: { id: "{$id}" }) {
                deletedCustomerId
                userErrors { field message }
            }
        }
        GRAPHQL;

        $response = $this->graphql($mutation);

        if (($response['status'] ?? '') === 'error') {
            return $response;
        }

        $userErrors = $response['data']['customerDelete']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '422',
                'answer' => implode(' | ', array_column($userErrors, 'message'))
            ];
        }

        return ['status' => 'ok'];
    }
}
