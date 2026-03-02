<?php

class Shopify extends Ecommerce
{
    protected string $shop;
    protected string $accessToken;

    protected string $codeStr = 'SH-';

    # Constructor
    # Obtengo las credenciales desde la db usando el mypos_id del JWT
    public function __construct(array $session)
    {
        try {
            if (empty($session['mypos_id']) || empty($session['client_id'])) {
                throw new RuntimeException('Sesión inválida');
            }

            # Obtengo los datos de la sesión
            $mypos_id  = (string) $session['mypos_id'];
            $client_id = (string) $session['client_id'];

            # Conexión a la base de datos
            $db = Database::getConnection();

            # Obtengo la configuración de la plataforma almacenada en la base de datos
            # Busca la fila donde platform = 'shopify' para mi mypos_id
            $stmt = $db->prepare("
                SELECT config
                FROM platforms_config
                WHERE mypos_id = ?
                AND platform = 'shopify'
                AND status = 'connected'
                LIMIT 1
            ");

            # Parametros de la consulta
            $stmt->bind_param("s", $mypos_id);
            $stmt->execute();

            $result = $stmt->get_result();
            $platform = $result->fetch_assoc();

            $stmt->close();

            if (!$platform) {
                throw new RuntimeException('Shopify no conectado');
            }

            $config = json_decode($platform['config'] ?? '', true); # JSON decodificado

            if (
                empty($config['shop']) ||
                empty($config['access_token'])
            ) {
                throw new RuntimeException('Configuración incompleta de Shopify');
            }

            $this->shop        = $config['shop'];
            $this->accessToken = $config['access_token'];

            # Inicializa Ecommerce con la URL base de la API REST de Shopify
            parent::__construct(
                "https://{$this->shop}/admin/api/2026-01",
                $mypos_id,
                $client_id
            );

            # Headers obligatorios para Shopify
            $this->headers = [
                'Content-Type: application/json',
                'X-Shopify-Access-Token: ' . $this->accessToken
            ];
        } catch (Throwable $e) {
            print 'Shopify::__construct -> ' . $e->getMessage();
        }
    }

    /* ================= GRAPHQL CORE ================= */

    # Ejecuto una consulta GraphQL hacía Shopify
    protected function graphql(string $query): array
    {
        return $this->request('POST', '/graphql.json', [
            'query' => $query
        ]);
    }

    /* ================= NORMALIZADORES ================= */

    # Normaliza productos de Shopify al formato de Ecommerce
    private function normalizeProducts(array $graphqlData): array
    {
        $edges = $graphqlData['data']['products']['edges'] ?? [];
        $json  = [];

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];

            $productTitle   = $node['title'] ?? null;
            $productDesc    = $node['description'] ?? null;
            $productTags    = $node['tags'] ?? [];
            $productStatus  = ($node['status'] ?? '') === 'ACTIVE';
            $productImages  = [];

            # Recolectar imágenes del producto (hasta 5)
            if (!empty($node['media']['nodes'])) {
                foreach ($node['media']['nodes'] as $m) {
                    $img = $m['preview']['image'] ?? null;
                    if ($img && !empty($img['url'])) {
                        $productImages[] = [
                            'url' => $img['url'],
                            'alt' => $img['altText'] ?? null
                        ];
                    }
                }
            }

            # Variantes (cada variante se convertirá en un producto independiente)
            $variantsNodes = $node['variants']['nodes'] ?? [];

            # Si no hay variantes, mantenemos la lógica previa: una variante vacía
            if (empty($variantsNodes)) {
                $json[] = [
                    'id' => $node['id'] ?? null,
                    'name' => ['es' => $productTitle],
                    'description' => ['es' => $productDesc],
                    'variants' => [[
                        'price' => 0.0,
                        'promotional_price' => null,
                        'stock' => 0,
                        'sku' => null,
                        'barcode' => null,
                        'weight' => null,
                        'width' => null,
                        'height' => null,
                        'depth' => null,
                        'requires_shipping' => true
                    ]],
                    'images' => $productImages,
                    'published' => $productStatus,
                    'free_shipping' => false,
                    'categories' => [],
                    'tags' => $productTags,
                ];
                continue;
            }

            # Para cada variante, crear un "producto" independiente
            foreach ($variantsNodes as $variantNode) {
                # Precio y comparativo
                $priceAmount = isset($variantNode['price']) && $variantNode['price'] !== null
                    ? (float) $variantNode['price']
                    : 0.0;

                $promotionalAmount = isset($variantNode['compareAtPrice']) && $variantNode['compareAtPrice'] !== null
                    ? (float) $variantNode['compareAtPrice']
                    : null;

                # Stock
                $stock = isset($variantNode['inventoryQuantity'])
                    ? (int) $variantNode['inventoryQuantity']
                    : 0;

                # Peso y requiresShipping desde inventoryItem
                $inventoryItem = $variantNode['inventoryItem'] ?? null;
                $weightValue = null;
                $requiresShipping = null;
                if (is_array($inventoryItem)) {
                    if (!empty($inventoryItem['measurement']['weight']['value'])) {
                        $weightValue = (float) $inventoryItem['measurement']['weight']['value'];
                    }
                    if (array_key_exists('requiresShipping', $inventoryItem)) {
                        $requiresShipping = (bool) $inventoryItem['requiresShipping'];
                    }
                }

                # Construir nombre de variante a partir de selectedOptions si existen
                $optionParts = [];
                if (!empty($variantNode['selectedOptions']) && is_array($variantNode['selectedOptions'])) {
                    foreach ($variantNode['selectedOptions'] as $opt) {
                        if (!empty($opt['value'])) {
                            $optionParts[] = $opt['value'];
                        }
                    }
                } else {
                    # Fallback: usar el title de la variante
                    if (!empty($variantNode['title'])) {
                        $optionParts[] = $variantNode['title'];
                    }
                }

                # Nombre final: "Producto + opciones" (ej: "Playera Roja S")
                $variantSuffix = !empty($optionParts) ? ' ' . implode(' ', $optionParts) : '';
                $variantName = trim(($productTitle ?? '') . $variantSuffix);

                # Imagenes: usar las del producto padre; si se desea mapear imágenes por variante,
                # habría que pedir en la query media asociada a la variante (no siempre disponible).
                $images = $productImages;

                # id único por variante: usar el id de la variante para evitar colisiones
                $variantId = $variantNode['id'] ?? null;

                $json[] = [
                    # Usamos el id de la variante para identificar el "producto variante"
                    'id' => $variantId,
                    'name' => [
                        'es' => $variantName,
                    ],
                    'description' => [
                        'es' => $productDesc,
                    ],
                    # Guardamos la variante original dentro de variants por compatibilidad
                    'variants' => [[
                        'id' => $variantId,
                        'price' => $priceAmount,
                        'promotional_price' => $promotionalAmount,
                        'stock' => $stock,
                        'sku' => $variantNode['sku'] ?? null,
                        'barcode' => $variantNode['barcode'] ?? null,
                        'weight' => $weightValue,
                        'width' => null,
                        'height' => null,
                        'depth' => null,
                        'requires_shipping' => $requiresShipping ?? true,
                        # Opciones de la variante para referencia
                        'options' => array_map(function ($o) {
                            return ($o['name'] ?? '') . ':' . ($o['value'] ?? '');
                        }, $variantNode['selectedOptions'] ?? [])
                    ]],
                    'images' => $images,
                    'published' => $productStatus,
                    'free_shipping' => false,
                    'categories' => [],
                    'tags' => $productTags,
                ];
            }
        }

        return $json;
    }

    # Normaliza ordenes de Shopify al formato de Ecommerce

    private function normalizeOrders(array $graphqlData): array
    {
        # Normalizo las ordenes
        $edges = $graphqlData['data']['orders']['edges'] ?? [];
        $json  = [];

        # Transformo los datos de las ordenes a la estructura de AIZU
        foreach ($edges as $edge) {

            $node = $edge['node'] ?? []; # Node de Shopify

            $financialStatus = $node['displayFinancialStatus'] ?? null; # Estado financiero de la orden (ej: PAID, PENDING, etc)

            /* ================= CLIENTE ================= */

            $customerNode = $node['customer'] ?? []; # Datos del cliente

            $email = $customerNode['defaultEmailAddress']['emailAddress'] # Email
                ?? $customerNode['email'] # Email “antiguo” (deprecated, pero aún existe)
                ?? $node['email'] # Email “moderno”
                ?? null;

            $phone = $customerNode['defaultPhoneNumber']['phoneNumber']
                ?? null;

            # Normalizo los datos del cliente
            $customer = [
                'id'    => $customerNode['id'] ?? null,
                'name'  => $customerNode['displayName'] ?? null,
                'email' => $email,
                'phone' => $phone,
                'note'  => $customerNode['note'] ?? null,
            ];

            /* ================= DIRECCIÓN ================== */

            $shippingNode = $node['shippingAddress'] ?? null; # Dirección de envío
            $shippingAddress = null; # Normalizo la dirección de envío

            # Si existe dirección de envío, lo normaliza
            if ($shippingNode) {
                $shippingAddress = [
                    'address1'  => $shippingNode['address1'] ?? null,
                    'address2'  => $shippingNode['address2'] ?? null,
                    'city'      => $shippingNode['city'] ?? null,
                    'province'  => $shippingNode['province'] ?? null,
                    'country'   => $shippingNode['country'] ?? null,
                    'zip'       => $shippingNode['zip'] ?? null,
                    'phone'     => $shippingNode['phone'] ?? null,
                    'latitude'  => $shippingNode['latitude'] ?? null,
                    'longitude' => $shippingNode['longitude'] ?? null,
                ];
            }

            $products = [];

            $lineItems = $node['lineItems']['edges'] ?? []; # Lineas de productos

            foreach ($lineItems as $liEdge) { # Node de cada línea de producto

                $li = $liEdge['node'] ?? [];

                $variantNode = $li['variant'] ?? []; # Variante de la línea de producto
                $productNode = $li['product'] ?? []; # Producto de la línea de producto

                # Normalizo los precios originales y descuentos
                $originalUnit = isset($li['originalUnitPriceSet']['shopMoney']['amount'])
                    ? (float) $li['originalUnitPriceSet']['shopMoney']['amount']
                    : null;

                # Normalizo los descuentos
                $discountedUnit = isset($li['discountedUnitPriceSet']['shopMoney']['amount'])
                    ? (float) $li['discountedUnitPriceSet']['shopMoney']['amount']
                    : null;

                # Normalizo el peso
                $weight = $variantNode['inventoryItem']['measurement']['weight']['value'] ?? null;

                # Agrego el producto a la lista de productos
                $products[] = [
                    'id' => $productNode['id'] ?? null,
                    'variant_id' => $variantNode['id'] ?? null,
                    'name' => $li['name'] ?? null,
                    'quantity' => (int) ($li['quantity'] ?? 0),
                    'current_quantity' => (int) ($li['currentQuantity'] ?? 0),
                    'unit_price_original' => $originalUnit,
                    'unit_price_discounted' => $discountedUnit,
                    'sku' => $variantNode['sku'] ?? $li['sku'] ?? null,
                    'barcode' => $variantNode['barcode'] ?? null,
                    'inventory_quantity' => $variantNode['inventoryQuantity'] ?? null,
                    'weight' => $weight,
                    'product_type' => $productNode['productType'] ?? null,
                    'description' => $productNode['descriptionHtml'] ?? null,
                    'total_inventory' => $productNode['totalInventory'] ?? null
                ];
            }

            # Agrego la orden a la lista de ordenes
            $json[] = [
                'id' => $node['id'] ?? null,
                'number' => $node['name'] ?? null,
                'created_at' => $node['createdAt'] ?? null,
                'status' => $financialStatus,
                'payment_status' => $financialStatus,
                'payment_gateways' => $node['paymentGatewayNames'] ?? [],
                'customer' => $customer,
                'shipping_address' => $shippingAddress,
                'products' => $products,
                'note' => $node['note'] ?? null,
                'order_origin' => 'shopify'
            ];
        }

        return $json;
    }

    # Normaliza clientes de Shopify al formato de Ecommerce
    private function normalizeCustomers(array $graphqlData): array
    {
        $edges = $graphqlData['data']['customers']['edges'] ?? []; # Edges de los clientes
        $json  = [];

        foreach ($edges as $edge) {

            $node = $edge['node'] ?? []; # Node de Shopify

            /* ================= EMAIL ================= */

            $email = $node['defaultEmailAddress']['emailAddress']
                ?? $node['email']
                ?? null;

            /* ================= PHONE ================= */

            $phone = $node['defaultPhoneNumber']['phoneNumber']
                ?? $node['phone']
                ?? null;

            /* ================= DIRECCIÓN ================= */

            $addr = $node['addresses'][0] ?? []; # Dirección (tomo la primera dirección del array de direcciones, si existe)

            # Normalizo los datos del cliente
            $json[] = [
                'id'    => $node['id'] ?? null,
                'name'  => $node['displayName'] ?? null,
                'email' => $email,
                'phone' => $phone,
                'note'  => $node['note'] ?? null,

                'address' => [
                    'address1' => $addr['address1'] ?? null,
                    'address2' => $addr['address2'] ?? null,
                    'city'     => $addr['city'] ?? null,
                    'province' => $addr['province'] ?? null,
                    'country'  => $addr['country'] ?? null,
                    'zip'      => $addr['zip'] ?? null,
                    'latitude' => $addr['latitude'] ?? null,
                    'longitude' => $addr['longitude'] ?? null
                ]
            ];
        }

        return $json;
    }
    /* ================= PRODUCTOS ================= */

    # Obtiene productos desde Shopify y los normaliza
    protected function getProducts(): array
    {
        try {
            $query = <<<GRAPHQL
            query GetDetailedProducts {
            products(first: 50) {
                edges {
                cursor
                node {
                    id
                    title
                    description
                    status
                    tags
                    totalInventory
                    createdAt

                    variants(first: 250) {
                    nodes {
                        id
                        title
                        sku
                        barcode
                        price
                        compareAtPrice
                        inventoryQuantity
                        selectedOptions {
                        name
                        value
                        }
                        inventoryItem {
                        requiresShipping
                        measurement {
                            weight {
                            value
                            unit
                            }
                        }
                        }
                    }
                    }

                    media(first: 5, query: "media_type:IMAGE") {
                    nodes {
                        preview {
                        image {
                            url
                            altText
                        }
                        }
                    }
                    }

                    options {
                    id
                    name
                    values
                    }
                }
                }
                pageInfo {
                hasNextPage
                endCursor
                }
            }
            }
            GRAPHQL;

            $response = $this->graphql($query); # Ejecuta la consulta GraphQL

            if ($response['status'] === 'error') {
                return [
                    'status' => 'error',
                    'code'   => $this->codeStr . '500',
                    'answer' => 'No se pudieron obtener los productos de Shopify',
                    'data'   => []
                ];
            }

            # Normaliza los productos
            return [
                'status' => 'ok',
                'code'   => $this->codeStr . '200',
                'answer' => 'Productos obtenidos',
                'data'   => $this->normalizeProducts($response['data'])
            ];
        } catch (Throwable $e) { # Log interno del error real
            error_log('Shopify::getProducts -> ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno al consultar productos en Shopify',
                'data'   => []
            ];
        }
    }

    # Crea un producto en Shopify y los normaliza
    protected function createItem(array $data): array
    {
        # Normalizar los datos del producto para el payload de Shopify
        $payload = [
            "product" => [
                "title" => $data['item_nombre'],
                "body_html" => $data['descripcion'],
                "vendor" => "MyPOS",
                "product_type" => "General",
                "tags" => implode(',', $data['categoría'] ?? []),
                "variants" => [[
                    "price" => (float)$data['precio'],
                    "sku" => $data['codigo_interno'] ?? null,
                    "barcode" => $data['codigo_barra'] ?? null,
                    "inventory_management" => "shopify",
                    "inventory_quantity" => (int)$data['stock_actual'],
                    "requires_shipping" => $data['servicio'] == 1 ? false : true,
                    "weight" => (float)($data['dimensiones']['peso'] ?? 0),
                    "weight_unit" => "kg"
                ]]
            ]
        ];

        return $this->request(
            'POST',
            '/products.json',
            $payload
        );
    } # Enviar al API

    # Actualiza un producto en Shopify y los normaliza
    protected function updateItem(string $externalId, array $data): array
    {
        $payload = [
            "product" => [
                "id" => (int)$externalId,
                "title" => $data['item_nombre'],
                "body_html" => $data['descripcion'],
                "tags" => implode(',', $data['categoría'] ?? [])
            ]
        ];

        return $this->request(
            'PUT',
            "/products/{$externalId}.json",
            $payload
        );
    } # Enviar al API

    /* ================= ORDENES ================= */

    # Obtiene ordenes desde Shopify y las normaliza
    protected function getOrders(): array
    {
        try {
            $query = <<<GRAPHQL
            query GetDetailedOrders {
            orders(first: 50, sortKey: CREATED_AT, reverse: true) {
                edges {
                node {
                    id
                    name
                    createdAt
                    displayFinancialStatus
                    paymentGatewayNames

                    # Email usado en la orden (puede diferir del email actual del customer)
                    email

                    customer {
                    id
                    displayName
                    firstName
                    lastName

                    # Deprecated, pero aún existe:
                    email

                    # Email “moderno”
                    defaultEmailAddress {
                        emailAddress
                    }

                    defaultPhoneNumber {
                        phoneNumber
                    }

                    note

                    # Dirección por defecto del cliente (no necesariamente la de esta orden)
                    defaultAddress {
                        address1
                        address2
                        city
                        province
                        country
                        zip
                    }
                    }

                    # Dirección de envío real de ESTA orden
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
            }
            }
            GRAPHQL;

            $response = $this->graphql($query); # Ejecuta la consulta GraphQL

            if ($response['status'] === 'error') {
                return [
                    'status' => 'error',
                    'code'   => $this->codeStr . '500',
                    'answer' => 'No se pudieron obtener las órdenes de Shopify',
                    'data'   => []
                ];
            }

            # Normaliza las ordenes
            return [
                'status' => 'ok',
                'code'   => $this->codeStr . '200',
                'answer' => 'Órdenes obtenidas',
                'data'   => $this->normalizeOrders($response['data'])
            ];
        } catch (Throwable $e) {
            error_log('Shopify::getOrders -> ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno al consultar órdenes en Shopify',
                'data'   => []
            ];
        }
    }

    /* ================= CLIENTES ================= */

    # Obtiene los clientes de Shopify y los normaliza
    protected function getCustomers(): array
    {
        try {

            $query = <<<GRAPHQL
            query GetCustomers {
            customers(first: 50) {
                edges {
                node {
                    id
                    displayName

                    defaultEmailAddress {
                    emailAddress
                    }

                    defaultPhoneNumber {
                    phoneNumber
                    }

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
            }
            }
            GRAPHQL;

            $response = $this->graphql($query);

            if ($response['status'] === 'error') {
                return $response;
            }

            return [
                'status' => 'ok',
                'code'   => $this->codeStr . '200',
                'answer' => 'Clientes obtenidos',
                'data'   => $this->normalizeCustomers($response['data'])
            ];
        } catch (Throwable $e) {

            error_log('Shopify::getCustomers -> ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno al consultar clientes',
                'data'   => []
            ];
        }
    }

    # Mapea el código de país a su nombre
    private function mapCountryCodeToName(?string $code): ?string
    {
        if (!$code) return null;

        $map = [
            'MX' => 'Mexico',
            'US' => 'United States',
            'ES' => 'Spain',
            'AR' => 'Argentina',
            'CO' => 'Colombia',
            'CL' => 'Chile',
            'PE' => 'Peru'
        ];

        return $map[strtoupper($code)] ?? $code;
    }

    # Crea un cliente en Shopify y lo normaliza
    protected function createCustomer(array $data): array
    {
        $direccion = $data['direccion'] ?? []; # Normalizar dirección

        $nameParts = explode(' ', $data['nombre'] ?? ''); # Normalizar nombre
        $firstName = $nameParts[0] ?? '';
        $lastName  = implode(' ', array_slice($nameParts, 1));

        # Normalizar los datos del cliente para el payload de Shopify
        $payload = [
            "customer" => [
                "first_name" => $firstName,
                "last_name"  => $lastName,
                "email"      => $data['email'] ?? null,
                "phone"      => $data['movil'] ?? $data['telefono'] ?? null,
                "note"       => $data['notas'] ?? null,
                "verified_email" => true,
                "addresses" => [[
                    "address1" => trim(($direccion['calle'] ?? '') . ' ' . ($direccion['no_ext'] ?? '')),
                    "address2" => '',
                    "city"     => $direccion['ciudad'] ?? '',
                    "province" => $direccion['estado'] ?? '',
                    "country"  => $this->mapCountryCodeToName($direccion['pais'] ?? null),
                    "zip"      => $direccion['cp'] ?? '',
                    "phone"    => $data['movil'] ?? null
                ]]
            ]
        ];

        return $this->request('POST', '/customers.json', $payload); # Enviar al API
    }

    # Actualiza un cliente en Shopify y lo normaliza
    protected function updateCustomer(string $externalId, array $data): array
    {
        $nameParts = explode(' ', $data['nombre'] ?? '');
        $firstName = $nameParts[0] ?? '';
        $lastName  = implode(' ', array_slice($nameParts, 1));

        # Normalizar los datos del cliente para el payload de Shopify
        $payload = [
            "customer" => [
                "id" => (int)$externalId,
                "first_name" => $firstName,
                "last_name"  => $lastName,
                "email"      => $data['email'] ?? null,
                "phone"      => $data['movil'] ?? $data['telefono'] ?? null,
                "note"       => $data['notas'] ?? null
            ]
        ];

        return $this->request(
            'PUT',
            "/customers/{$externalId}.json",
            $payload
        ); # Enviar al API
    }
}
