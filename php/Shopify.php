<?php
class Shopify
{
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
            return ['status' => 'error', 'message' => curl_error($ch)];
        }
        curl_close($ch);

        return json_decode($response, true) ?? ['status' => 'error', 'message' => 'Invalid JSON'];
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

            $products = $response['data']['products'] ?? [];
            $edges = $products['edges'] ?? [];

            foreach ($edges as $edge) {

                $cursor = $edge['cursor'];
                $node = $edge['node'];

                $categoria = [];
                if (!empty($node['category']['fullName'])) {
                    $categoria = array_map('trim', explode('>', $node['category']['fullName']));
                }

                foreach ($node['variants']['nodes'] as $variant) {

                    $selectedOptions = $variant['selectedOptions'] ?? [];
                    $variantTitle = $variant['title'] ?? null;
                    if ($variantTitle === 'Default Title' || $variantTitle === null) {
                        $variants = [];
                    } else {
                        $variants = $selectedOptions;
                    }

                    $raw[] = [
                        'item_id'        => $variant['id'],
                        'padre_id'       => count($node['variants']['nodes']) > 1 ? $node['id'] : null,
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

            $response   = $this->graphql($mutation);
            $userErrors = $response['data']['productCreate']['userErrors'] ?? [];

            if (!empty($userErrors)) {
                return ['error' => 'Shopify: ' . implode(' | ', array_column($userErrors, 'message'))];
            }

            $product = $response['data']['productCreate']['product'] ?? null;
            if (!$product) return ['error' => 'Shopify no devolvió el producto creado'];

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
                $precio, $stock, $sku, $barcode, $requiresShipping
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

        $response   = $this->graphql($mutation);
        $userErrors = $response['data']['productCreate']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            return ['error' => 'Shopify: ' . implode(' | ', array_column($userErrors, 'message'))];
        }

        $product = $response['data']['productCreate']['product'] ?? null;
        if (!$product) return ['error' => 'Shopify no devolvió el producto creado'];

        $productGid   = $product['id'];
        $variantNodes = $product['variants']['nodes'] ?? [];
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
                locationId: \"gid://shopify/Location/1\"
            }]
            inventoryItem: { requiresShipping: {$shipStr} }
        }";
    }

    # <--- ORDERS --->

    public function fetchRawOrders(array $params = []): array
    {
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {

            $after = $cursor ? ", after: \"$cursor\"" : "";

            $query = <<<GRAPHQL
            query{
            orders(first: 100 $after, sortKey: CREATED_AT, reverse: true) {
                edges {
                cursor
                node {
                    id
                    name
                    createdAt
                    displayFinancialStatus
                    paymentGatewayNames
                    email
                    customer {
                    id
                    displayName
                    firstName
                    lastName
                    email
                    defaultEmailAddress { emailAddress }
                    defaultPhoneNumber { phoneNumber }
                    note
                    defaultAddress {
                        address1
                        address2
                        city
                        province
                        country
                        zip
                    }
                    }
                    shippingAddress {
                    address1
                    address2
                    city
                    province
                    country
                    zip
                    phone
                    latitude
                    longitude
                    }
                    lineItems(first: 50) {
                    edges {
                        node {
                        id
                        name
                        quantity
                        currentQuantity
                        sku
                        originalUnitPriceSet {
                            shopMoney {
                            amount
                            currencyCode
                            }
                        }
                        discountedUnitPriceSet {
                            shopMoney {
                            amount
                            currencyCode
                            }
                        }
                        variant {
                            id
                            title
                            sku
                            barcode
                            inventoryQuantity
                            inventoryItem {
                            measurement {
                                weight {
                                value
                                unit
                                }
                            }
                            }
                        }
                        product {
                            id
                            title
                            productType
                            totalInventory
                            descriptionHtml
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
            $edges = $response['data']['orders']['edges'] ?? [];
            $raw = [];

            foreach ($edges as $edge) {
                $cursor = $edge['cursor'];
                $node = $edge['node'];

                $phoneData = Utils::extractPhoneData($node['shippingAddress']['phone'] ?? null);
                // Cliente
                $cliente = [
                    'id'        => $node['customer']['id'] ?? null,
                    'origen'    => 'Shopify',
                    'telefono'  => $phoneData['numero'],
                    'movil'     => $node['customer']['defaultPhoneNumber']['phoneNumber'] ?? null,
                    'lada'      => $phoneData['lada'],
                    'notas'     => $node['customer']['note'] ?? null,
                    'nombre'    => $node['customer']['displayName'] ?? null,
                    'rfc'       => null,
                    'email'     => $node['customer']['defaultEmailAddress']['emailAddress']
                        ?? $node['customer']['email']
                        ?? $node['email'] ?? null,
                    'prospecto' => 0,
                    'direccion' => [
                        'calle'       => $node['shippingAddress']['address1'] ?? null,
                        'no_ext'      => null,
                        'no_int'      => $node['shippingAddress']['address2'] ?? null,
                        'colonia'     => null,
                        'cp'          => $node['shippingAddress']['zip'] ?? null,
                        'municipio'   => $node['shippingAddress']['city'] ?? null,
                        'estado'      => $node['shippingAddress']['province'] ?? null,
                        'ciudad'      => $node['shippingAddress']['city'] ?? null,
                        'pais'        => $node['shippingAddress']['country'] ?? null,
                        'referencias' => null,
                        'gps'         => [
                            'latitud'  => $node['shippingAddress']['latitude'] ?? null,
                            'longitud' => $node['shippingAddress']['longitude'] ?? null
                        ]
                    ]
                ];

                // Vendedor
                $vendedor = [
                    'aizu_id' => null,
                    'user'    => 'Shopify',
                    'nombre'  => 'Shopify'
                ];

                // Pago
                $pago = [
                    'cuenta_receptora' => [
                        'id'          => null,
                        'clabe'       => null,
                        'beneficiario' => null,
                        'comision'    => [
                            'aizu_id'   => null,
                            'nombre'    => null,
                            'porcentaje' => null,
                            'importe'   => null
                        ]
                    ],
                    'cuenta_emisora' => null,
                    'forma_pago'     => implode(',', $node['paymentGatewayNames'] ?? []),
                    'metodo_pago'    => $node['displayFinancialStatus'] ?? null
                ];

                // Partes
                $partes = [];
                foreach ($node['lineItems']['edges'] as $li) {
                    $line = $li['node'];
                    $partes[] = [
                        'item_id'        => $line['product']['id'] ?? $line['id'],
                        'item_aizu_id'   => null,
                        'item_nombre'    => $line['product']['title'] ?? $line['name'],
                        'categoría'      => [$line['product']['productType']] ?? [],
                        'cant'           => $line['quantity'],
                        'precio'         => isset($line['originalUnitPriceSet']['shopMoney']['amount'])
                            ? (float)$line['originalUnitPriceSet']['shopMoney']['amount']
                            : null,
                        'codigo_barra'   => $line['variant']['barcode'] ?? null,
                        'codigo_interno' => $line['sku'] ?? $line['variant']['sku'] ?? null,
                        'stock_actual'   => $line['variant']['inventoryQuantity'] ?? null,
                        'descripcion'    => $line['product']['descriptionHtml'] ?? null,
                        'ficha_tecnica'  => null,
                        'servicio'       => isset($line['variant']['inventoryItem']['measurement']['weight']['value'])
                            && $line['variant']['inventoryItem']['measurement']['weight']['value'] > 0 ? 0 : 1,
                        'fiscal' => [
                            'unidad' => null,
                            'clave'  => null,
                            'iva'    => null,
                            'ieps'   => null
                        ],
                        'dimensiones' => [
                            'alto'  => null,
                            'ancho' => null,
                            'largo' => null,
                            'peso'  => $line['variant']['inventoryItem']['measurement']['weight']['value'] ?? null
                        ]
                    ];
                }

                $raw[] = [
                    'id'        => $node['id'],
                    'name'      => $node['name'],
                    'createdAt' => $node['createdAt'],
                    'cliente'   => $cliente,
                    'vendedor'  => $vendedor,
                    'pago'      => $pago,
                    'partes'    => $partes,
                    'notas'     => $node['customer']['note'] ?? null
                ];
            }

            $hasNextPage = $response['data']['orders']['pageInfo']['hasNextPage'] ?? false;
            }
            return $raw;
    }

    # <--- CUSTOMERS --->

    public function fetchRawCustomers(array $params = []): array
    {
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {

            $after = $cursor ? ", after: \"$cursor\"" : "";
            $query = <<<GRAPHQL
            query {
            customers(first: 100 $after) {
                edges {
                cursor
                node {
                    id
                    displayName
                    defaultEmailAddress { emailAddress }
                    defaultPhoneNumber { phoneNumber }
                    email
                    phone
                    note
                    addresses {
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
                pageInfo {
                hasNextPage
                }
            }
            }
            GRAPHQL;

            $response = $this->graphql($query);
            $edges = $response['data']['customers']['edges'] ?? [];
            $raw = [];

            foreach ($edges as $edge) {
                $node = $edge['node'];

                $direccion = [];
                if (!empty($node['addresses'])) {
                    $addr = $node['addresses'][0];
                    $direccion = [
                        'calle'       => $addr['address1'] ?? null,
                        'no_ext'      => null,
                        'no_int'      => $addr['address2'] ?? null,
                        'colonia'     => null,
                        'cp'          => $addr['zip'] ?? null,
                        'municipio'   => $addr['city'] ?? null,
                        'estado'      => $addr['province'] ?? null,
                        'ciudad'      => $addr['city'] ?? null,
                        'pais'        => $addr['country'] ?? null,
                        'referencias' => null,
                        'gps' => [
                            'latitud'  => $addr['latitude'] ?? null,
                            'longitud' => $addr['longitude'] ?? null
                        ]
                    ];
                }

                $phoneData = Utils::extractPhoneData($node['phone'] ?? null);

                $raw[] = [
                    'id'        => $node['id'],
                    'origen'    => 'Shopify',
                    'telefono'  => $phoneData['numero'],
                    'movil'     => $node['defaultPhoneNumber']['phoneNumber'] ?? null,
                    'lada'      => $phoneData['lada'],
                    'notas'     => $node['note'] ?? null,
                    'nombre'    => $node['displayName'] ?? null,
                    'rfc'       => null,
                    'email'     => $node['defaultEmailAddress']['emailAddress'] ?? $node['email'] ?? null,
                    'prospecto' => 0,
                    'direccion' => $direccion
                ];
            }
            $hasNextPage = $response['data']['customers']['pageInfo']['hasNextPage'] ?? false;
        }
        return $raw;
    }
}
