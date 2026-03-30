<?php

class TiendaNube
{
    private string $codeStr = 'TN-';

    private string $user_Id;
    private string $api_token;
    private string $baseUrl;
    private array $headers;

    public function __construct()
    {
        $this->user_Id   = getenv('TIENDANUBE_USER_ID');
        $this->api_token = getenv('TIENDANUBE_API_TOKEN');

        $this->baseUrl = "https://api.tiendanube.com/v1/{$this->user_Id}";

        $this->headers = [
            'Authentication: bearer ' . $this->api_token,
            'User-Agent: MyPOS Integration',
            'Content-Type: application/json'
        ];
    }

    public function getName(): string
    {
        return 'TiendaNube';
    }

    # <--- REQUEST --->

    private function request(string $method, string $endpoint): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            curl_close($ch);
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error de conexión con TiendaNube'
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $msg = $decoded['description'] ?? $decoded['message'] ?? ('HTTP ' . $httpCode);
            return [
                'status' => 'error',
                'code'   => $this->codeStr . $httpCode,
                'answer' => $msg
            ];
        }

        return $decoded;
    }

    private function requestWithBody(string $method, string $endpoint, array $body = []): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);

        if ($response === false) {
            curl_close($ch);
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error de conexión con TiendaNube'
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            # TiendaNube devuelve errores de validación en 'errors' (array por campo)
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                $parts = [];
                foreach ($decoded['errors'] as $field => $messages) {
                    $msgs   = is_array($messages) ? implode(', ', $messages) : $messages;
                    $parts[] = ucfirst($field) . ': ' . $msgs;
                }
                $msg = implode(' | ', $parts);
            } else {
                $msg = $decoded['message'] ?? $decoded['description'] ?? ('HTTP ' . $httpCode);
            }

            return [
                'status' => 'error',
                'code'   => $this->codeStr . $httpCode,
                'answer' => $msg
            ];
        }

        return $decoded;
    }

    # <--- PRODUCTS --->

    # Obtiene desde la API de TiendaNube los productos
    public function fetchRawProducts(array $params = []): array
    {
        $page = 1;
        $raw = [];

        do {

            $products = $this->request(
                'GET',
                "/products?page={$page}&per_page=200"
            );

            if (empty($products) || ($products['status'] ?? '') === 'error') {
                break;
            }

            foreach ($products as $p) {

                $productName = is_array($p['name'] ?? null)
                    ? ($p['name']['es'] ?? $p['name']['en'] ?? null)
                    : ($p['name'] ?? null);

                $description = is_array($p['description'] ?? null)
                    ? ($p['description']['es'] ?? $p['description']['en'] ?? null)
                    : ($p['description'] ?? null);

                if ($description !== null) {
                    $description = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                }

                $categoria = [];

                if (!empty($p['categories'])) {
                    foreach ($p['categories'] as $cat) {
                        $categoria[] = $cat['name']['es'] ?? $cat['name']['en'] ?? null;
                    }
                }

                $isService = !($p['requires_shipping'] ?? true);

                $variants = $p['variants'] ?? [];

                if (empty($variants)) {
                    $variants = [[
                        'id'      => $p['id'],
                        'values'  => [],
                        'price'   => $p['price'] ?? null,
                        'stock'   => $p['stock'] ?? null,
                        'sku'     => null,
                        'barcode' => null
                    ]];
                }

                foreach ($variants as $v) {

                    $variantValues = [];

                    if (!empty($v['values'])) {

                        foreach ($v['values'] as $i => $val) {

                            $variantValues[] = [
                                'name' => $p['attributes'][$i]['es']
                                    ?? $p['attributes'][$i]['en']
                                    ?? null,

                                'value' => $val['es']
                                    ?? $val['en']
                                    ?? null
                            ];
                        }
                    }

                    $raw[] = [
                        'item_id'        => $v['id'],
                        'padre_id'       => count($variants) > 1 ? $p['id'] : null,
                        'item_nombre'    => $productName,
                        'variants'       => $variantValues,
                        'categoría'      => $categoria,
                        'descripcion'    => $description,
                        'stock_actual'   => $v['stock'] ?? null,
                        'servicio'       => $isService ? 1 : 0,
                        'precio'         => isset($v['price']) ? (float)$v['price'] : null,
                        'codigo_barra'   => $v['barcode'] ?? null,
                        'codigo_interno' => $v['sku'] ?? null
                    ];
                }
            }

            $page++;
        } while (count($products) === 200);

        return $raw;
    }

    # Crea un producto en TiendaNube
    public function createProduct(array $item): array
    {
        $rawVariants = $item['variants'] ?? [];
        $descripcion = $item['descripcion'] ?? null;

        $body = [
            'name'      => ['es' => $item['item_nombre'] ?? ''],
            'published' => true,
        ];

        if ($descripcion) {
            $body['description'] = ['es' => $descripcion];
        }

        $catIds = array_filter($item['categoria'] ?? [], 'is_numeric');
        if (!empty($catIds)) {
            $body['categories'] = array_map(fn($id) => ['id' => (int)$id], array_values($catIds));
        }

        if (empty($rawVariants)) {
            $body['price'] = (float)($item['precio'] ?? 0);

            $body['variants'] = [[
                'price'   => (float)($item['precio']       ?? 0),
                'stock'   => (int)($item['stock_actual']   ?? 0),
                'sku'     => $item['codigo_interno'] ?? null,
                'barcode' => $item['codigo_barra']   ?? null,
            ]];

            $response = $this->requestWithBody('POST', '/products', $body);

            if (empty($response) || ($response['status'] ?? '') === 'error') {
                return $response ?: [
                    'status' => 'error',
                    'code'   => $this->codeStr . '502',
                    'answer' => 'Sin respuesta de TiendaNube'
                ];
            }

            $productId = $response['id'] ?? null;

            if (!$productId) {
                return [
                    'status' => 'error',
                    'code'   => $this->codeStr . '502',
                    'answer' => 'TiendaNube no devolvió el producto creado'
                ];
            }

            $tnVariants = $response['variants'] ?? [];
            $variantId  = $tnVariants[0]['id']  ?? $productId;

            return [[
                'id_externo' => (string)$variantId,
                'padre_id'   => null
            ]];
        }

        $attributeNames = [];
        foreach ($rawVariants as $v) {
            $name = $v['name'] ?? null;
            if ($name && !in_array($name, $attributeNames, true)) {
                $attributeNames[] = $name;
            }
        }
        $body['attributes'] = array_map(fn($n) => ['es' => $n], $attributeNames);

        $tnVariants = [];
        foreach ($rawVariants as $v) {
            $tnVariants[] = [
                'price'   => (float)($v['precio']        ?? $item['precio']        ?? 0),
                'stock'   => (int)($v['stock_actual']     ?? $item['stock_actual']  ?? 0),
                'sku'     => $v['codigo_interno'] ?? $item['codigo_interno'] ?? null,
                'barcode' => $v['codigo_barra']   ?? $item['codigo_barra']   ?? null,
                'values'  => [['es' => $v['value'] ?? '']],
            ];
        }
        $body['variants'] = $tnVariants;

        $response = $this->requestWithBody('POST', '/products', $body);

        if (empty($response) || ($response['status'] ?? '') === 'error') {
            return $response ?: [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'Sin respuesta de TiendaNube'
            ];
        }

        $productId = $response['id'] ?? null;

        if (!$productId) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'TiendaNube no devolvió el producto creado'
            ];
        }

        $returnedVariants = $response['variants'] ?? [];
        $hasManyVariants  = count($returnedVariants) > 1;

        $result = [];
        foreach ($returnedVariants as $v) {
            $result[] = [
                'id_externo' => (string)($v['id'] ?? ''),
                'padre_id'   => $hasManyVariants ? (string)$productId : null
            ];
        }

        return $result;
    }

    # <--- ORDERS --->
 
    public function fetchRawOrders(): array
    {
        # Obtener todos los pedidos
        $allOrders = [];
        $page      = 1;

        do {
            $response = $this->request('GET', "/orders?per_page=200&page={$page}");

            if (empty($response) || !is_array($response)) {
                break;
            }

            $allOrders = array_merge($allOrders, $response);
            $page++;

        } while (count($response) === 200);

        $normalized = [];

        foreach ($allOrders as $order) {

            $orderId = $order['id'] ?? null;
            if (!$orderId) {
                continue;
            }

            # id_externo
            $idExterno = (string)$orderId;

            # customer
            $customerData  = $order['customer'] ?? [];
            $customerExtId = isset($customerData['id'])
                ? (string)$customerData['id']
                : '';

            # partes
            $partes = [];
            foreach (($order['products'] ?? []) as $prod) {

                $productId = $prod['product_id'] ?? ($prod['id'] ?? null);

                $partes[] = [
                    'item_id'        => $productId ? (string)$productId : '',
                    'item_aizu_id'   => null,
                    'item_nombre'    => (string)($prod['name'] ?? ''),
                    'cant'           => (int)($prod['quantity'] ?? 1),
                    'precio'         => (float)($prod['price'] ?? 0),
                    'codigo_barra'   => $prod['barcode'] ?? null,
                    'codigo_interno' => $prod['sku'] ?? null,
                    'stock_actual'   => null,
                    'descripcion'    => null,
                    'servicio'       => 0,
                ];
            }

            # PAGO 

            # Estado del pago
            $estadoPago = $order['payment_status'] ?? 'pending';

            # Forma de pago
            $formaPago = null;

            if (!empty($order['payment_name'])) {
                $name = strtolower($order['payment_name']);

                if (str_contains($name, 'tarjeta') || str_contains($name, 'card')) {
                    $formaPago = 'TARJETA';
                } elseif (str_contains($name, 'transfer')) {
                    $formaPago = 'TRANSFERENCIA';
                } elseif (str_contains($name, 'efectivo')) {
                    $formaPago = 'EFECTIVO';
                } elseif (str_contains($name, 'mercado')) {
                    $formaPago = 'MERCADO_PAGO';
                } elseif (str_contains($name, 'paypal')) {
                    $formaPago = 'PAYPAL';
                } else {
                    $formaPago = strtoupper($order['payment_name']);
                }
            }

            # fallback técnico
            if (!$formaPago && !empty($order['gateway'])) {
                $formaPago = strtoupper($order['gateway']);
            }

            # Método de pago -> falta que lo encuentre
            $metodoPago = null;

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
                'estado_pago'                   => $estadoPago,

                'moneda'                        => $order['currency'] ?? 'MXN',
                'tasa_moneda'                   => 1.0,
            ];

            # vendedor
            $vendedor = [
                'aizu_id' => null,
                'user'    => 'TiendaNube',
                'nombre'  => 'TiendaNube',
            ];

            # total
            $total = null;
            if (isset($order['total'])) {
                $total = (float)$order['total'];
            } elseif (isset($order['subtotal'])) {
                $total = (float)$order['subtotal'];
            }

            # notas
            $notas = $order['owner_note'] ?? null;

            $normalized[] = [
                'id_externo'      => $idExterno,
                'customer_ext_id' => $customerExtId,
                'vendedor'        => $vendedor,
                'pago'            => $pago,
                'partes'          => $partes,
                'total'           => $total,
                'notas'           => $notas,
            ];
        }

        return $normalized;
    }

    # <--- CUSTOMERS --->

    # Obtiene los clientes desde la API de TiendaNube
    public function fetchRawCustomers(array $params = []): array
    {
        $allCustomers = [];
        $page = 1;

        do {

            $response = $this->request(
                'GET',
                "/customers?page={$page}&per_page=200"
            );

            if (empty($response) || ($response['status'] ?? '') === 'error') {
                break;
            }

            $allCustomers = array_merge($allCustomers, $response);

            $page++;
        } while (count($response) === 200);

        $raw = [];

        foreach ($allCustomers as $c) {

            $phoneData = Utils::extractPhoneData($c['phone'] ?? null);
            $raw[] = [
                'id'        => $c['id'],
                'origen'    => 'TiendaNube',
                'telefono'  => $phoneData['numero'],
                'movil'     => $c['phone'] ?? null,
                'lada'      => $phoneData['lada'],
                'notas'     => $c['note'] ?? null,
                'nombre'    => $c['name'] ?? null,
                'rfc'       => null,
                'email'     => $c['email'] ?? null,
                'prospecto' => 0,
                'created_at' => $c['created_at'] ?? null,

                'direcciones' => [[
                    'calle'          => $c['default_address']['address'] ?? null,
                    'no_ext'         => $c['default_address']['number'] ?? null,
                    'no_int'         => $c['default_address']['floor'] ?? null,
                    'colonia'        => $c['default_address']['locality'] ?? null,
                    'cp'             => $c['default_address']['zipcode'] ?? null,
                    'municipio'      => $c['default_address']['city'] ?? null,
                    'estado'         => $c['default_address']['province'] ?? null,
                    'ciudad'         => $c['default_address']['city'] ?? null,
                    'pais'           => $c['default_address']['country'] ?? null,
                    'referencias'    => null,
                    'gps' => [
                        'latitud'  => null,
                        'longitud' => null
                    ],
                    'predeterminada' => true
                ]]
            ];
        }

        return $raw;
    }

    # Crea un cliente en TiendaNube
    public function createCustomer(array $customer): array
    {
        $phoneData = Utils::extractPhoneData($customer['movil'] ?? $customer['telefono'] ?? null);
        $phone = ($phoneData['lada'] ?? '') . ($phoneData['numero'] ?? '');

        $direccion = $customer['default_address'] ?? ($customer['direcciones'][0] ?? null);

        $body = [
            'name'           => $customer['nombre'] ?? '',
            'email'          => $customer['email'] ?? null,
            'phone'          => $phone ?: null,
            'identification' => $customer['rfc'] ?? null,
            'note'           => $customer['notas'] ?? null,
        ];

        if (!empty($direccion) && (!empty($direccion['calle']) || !empty($direccion['ciudad']) || !empty($direccion['cp']))) {

            $body['addresses'] = [[
                'address'  => $direccion['calle'] ?? '',
                'number'   => $direccion['no_ext'] ?? '',
                'floor'    => $direccion['no_int'] ?? '',
                'locality' => $direccion['colonia'] ?? '',
                'city'     => $direccion['ciudad'] ?? '',
                'province' => $direccion['estado'] ?? '',
                'country'  => Utils::countryToISO2($direccion['pais'] ?? 'Mexico'),
                'zipcode'  => $direccion['cp'] ?? '',
                'phone'    => $phone ?: null
            ]];
        }

        $response = $this->requestWithBody('POST', '/customers', $body);

        if (empty($response) || ($response['status'] ?? '') === 'error') {
            return $response ?: [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'Sin respuesta de TiendaNube'
            ];
        }

        $id = $response['id'] ?? null;

        if (!$id) {
            return [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'TiendaNube no devolvió el cliente creado'
            ];
        }

        return [[
            'id_externo' => (string)$id,
            'padre_id'   => null
        ]];
    }

    # Actualiza un cliente en TiendaNube
    public function updateCustomer(string $idExterno, array $customer): array
    {
        $body = [];

        if (array_key_exists('nombre', $customer)) {
            $body['name'] = $customer['nombre'] ?? '';
        }

        if (array_key_exists('email', $customer)) {
            $body['email'] = $customer['email'] ?? null;
        }

        if (array_key_exists('notas', $customer)) {
            $body['note'] = $customer['notas'] ?? null;
        }

        if (array_key_exists('rfc', $customer)) {
            $body['identification'] = $customer['rfc'] ?? null;
        }

        if (array_key_exists('movil', $customer) || array_key_exists('telefono', $customer)) {
            $phoneData    = Utils::extractPhoneData($customer['movil'] ?? $customer['telefono'] ?? null);
            $phone        = ($phoneData['lada'] ?? '') . ($phoneData['numero'] ?? '');
            $body['phone'] = $phone ?: null;
        }

        if (array_key_exists('direcciones', $customer) && !empty($customer['direcciones'])) {
            $dir = $customer['direcciones'][0];
            $body['default_address'] = [
                'address'  => $dir['calle']    ?? '',
                'number'   => $dir['no_ext']   ?? '',
                'floor'    => $dir['no_int']   ?? '',
                'locality' => $dir['colonia']  ?? '',
                'city'     => $dir['ciudad']   ?? '',
                'province' => $dir['estado']   ?? '',
                'country'  => Utils::countryToISO2($dir['pais'] ?? 'Mexico'),
                'zipcode'  => $dir['cp']       ?? '',
                'phone'    => $body['phone'] ?? null
            ];
        }

        if (empty($body)) {
            return ['status' => 'ok'];
        }

        $response = $this->requestWithBody('PUT', "/customers/{$idExterno}", $body);

        if (empty($response) || ($response['status'] ?? '') === 'error') {
            return $response ?: [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'Sin respuesta de TiendaNube al actualizar'
            ];
        }

        return ['status' => 'ok'];
    }

    # Obtiene las direcciones de un cliente específico en TiendaNube
    public function fetchCustomerAddresses(string $idExterno): array
    {
        $response = $this->request('GET', "/customers/{$idExterno}");

        if (empty($response) || ($response['status'] ?? '') === 'error') {
            return [];
        }

        $dir = $response['default_address'] ?? null;
        if (!$dir) return [];

        return [[
            'calle'          => $dir['address']  ?? null,
            'no_ext'         => $dir['number']   ?? null,
            'no_int'         => $dir['floor']    ?? null,
            'colonia'        => $dir['locality'] ?? null,
            'cp'             => $dir['zipcode']  ?? null,
            'municipio'      => $dir['city']     ?? null,
            'estado'         => $dir['province'] ?? null,
            'ciudad'         => $dir['city']     ?? null,
            'pais'           => $dir['country']  ?? null,
            'referencias'    => null,
            'gps'            => ['latitud' => null, 'longitud' => null],
            'predeterminada' => true,
        ]];
    }

    public function supportsMultipleAddresses(): bool
    {
        return false;
    }

    # TiendaNube solo una dirección — predeterminada no aplica
    public function addAddress(string $customerId, array $address): array
    {
        return ['status' => 'ok'];
    }

    # Sincroniza la dirección predeterminada en TiendaNube - reemplaza la existente
    public function syncDefaultAddress(string $customerId, array $address): array
    {
        $body = [
            'default_address' => [
                'address'  => $address['calle']   ?? '',
                'number'   => $address['no_ext']  ?? '',
                'floor'    => $address['no_int']  ?? '',
                'locality' => $address['colonia'] ?? '',
                'city'     => $address['ciudad']  ?? '',
                'province' => $address['estado']  ?? '',
                'country'  => Utils::countryToISO2($address['pais'] ?? 'Mexico'),
                'zipcode'  => $address['cp']      ?? '',
            ]
        ];

        $response = $this->requestWithBody('PUT', "/customers/{$customerId}", $body);

        if (empty($response) || ($response['status'] ?? '') === 'error') {
            return $response ?: [
                'status' => 'error',
                'code'   => $this->codeStr . '502',
                'answer' => 'Sin respuesta de TiendaNube al sincronizar dirección'
            ];
        }

        return ['status' => 'ok'];
    }

    # Elimina un cliente en TiendaNube
    public function deleteCustomer(string $idExterno): array
    {
        $response = $this->request('DELETE', "/customers/{$idExterno}");

        if (($response['status'] ?? '') === 'error') {
            return $response;
        }

        return ['status' => 'ok'];
    }
}