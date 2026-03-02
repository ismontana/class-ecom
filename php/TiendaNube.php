<?php

class TiendaNube extends Ecommerce
{
    protected string $storeId;
    protected string $apiToken;

    protected string $codeStr = 'TN-';

    # Obtiene las credenciales desde la BD usando el mypos_id del JWT 
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
            # Busca la fila donde platform = 'tiendanube' para mi mypos_id
            $stmt = $db->prepare("
                SELECT config
                FROM platforms_config
                WHERE mypos_id = ?
                AND platform = 'tiendanube'
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
                throw new RuntimeException('Plataforma no conectada');
            }

            $config = json_decode($platform['config'] ?? '', true);

            if (
                empty($config['api_token']) ||
                empty($config['user_Id'])
            ) {
                throw new RuntimeException('Configuración incompleta de TiendaNube');
            }

            $this->storeId  = (string) $config['user_Id'];
            $this->apiToken = (string) $config['api_token'];

            # Inicializa Ecommerce con la URL base de la API REST de TiendaNube y los datos de la sesión
            parent::__construct(
                "https://api.tiendanube.com/v1/{$this->storeId}",
                $mypos_id,
                $client_id
            );

            # Headers obligatorios para TiendaNube
            $this->headers = [
                'Authentication: bearer ' . $this->apiToken,
                'User-Agent: MyPOS Integration',
                'Content-Type: application/json'
            ];
        } catch (Throwable $e) {
            print 'TiendaNube::__construct -> ' . $e->getMessage();
        }
    }

    /* ================= NORMALIZADORES ================= */

    # Normaliza productos de TiendaNube al formato de Ecommerce
    private function normalizeProducts(array $items): array
    {
        $json = [];

        foreach ($items as $p) {
            # Nombre y descripción del producto padre
            $productName = is_array($p['name'] ?? null)
                ? ($p['name']['es'] ?? $p['name']['en'] ?? null)
                : ($p['name'] ?? null);

            $productDescRaw = is_array($p['description'] ?? null)
                ? ($p['description']['es'] ?? $p['description']['en'] ?? null)
                : ($p['description'] ?? null);

            $productDesc = null;

            if (!empty($productDescRaw)) {

                // Convertir entidades HTML (&iquest; → ¿)
                $decoded = html_entity_decode($productDescRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Reemplazar </p> por doble salto de línea
                $decoded = str_replace('</p>', "\n\n", $decoded);

                // Quitar todas las etiquetas HTML restantes
                $clean = strip_tags($decoded);

                // Limpiar espacios extra
                $clean = preg_replace('/[ \t]+/', ' ', $clean);     // espacios múltiples
                $clean = preg_replace('/\n\s+/', " ", $clean);     // espacios después de salto
                $clean = trim($clean);

                $productDesc = $clean;
            }

            $productCategoriesRaw = $p['categories'] ?? [];
            $productCategories = [];

            if (!empty($productCategoriesRaw) && is_array($productCategoriesRaw)) {
                foreach ($productCategoriesRaw as $cat) {
                    if (is_array($cat)) {
                        $productCategories[] = [
                            'id'   => $cat['id'] ?? null,
                            'name' => $cat['name']['es'] ?? $cat['name']['en'] ?? null
                        ];
                    }
                }
            }
            $productPublished = !empty($p['published']);
            $productFreeShipping = !empty($p['free_shipping']);

            # Recolectar imágenes del producto padre
            $productImages = [];
            foreach ($p['images'] ?? [] as $img) {
                if (!empty($img['src'])) {
                    $productImages[] = [
                        'url' => $img['src'],
                        'alt' => $img['alt'] ?? null
                    ];
                }
            }

            # Variantes del producto padre
            $variantsList = $p['variants'] ?? [];

            # Si no hay variantes, mantenemos una entrada con la info del padre
            if (empty($variantsList)) {
                $json[] = [
                    'id' => $p['id'] ?? null,
                    'name' => ['es' => $productName],
                    'description' => ['es' => $productDesc],
                    'variants' => [[
                        'price' => (float) ($p['price'] ?? 0),
                        'promotional_price' => $p['promotional_price'] ?? null,
                        'stock' => (int) ($p['stock'] ?? 0),
                        'sku' => $p['sku'] ?? null,
                        'barcode' => $p['barcode'] ?? null,
                        'weight' => $p['weight'] ?? null,
                        'width' => $p['width'] ?? null,
                        'height' => $p['height'] ?? null,
                        'depth' => $p['depth'] ?? null,
                        'requires_shipping' => (bool) ($p['requires_shipping'] ?? true)
                    ]],
                    'images' => $productImages,
                    'published' => $productPublished,
                    'free_shipping' => $productFreeShipping,
                    'categories' => $productCategories
                ];
                continue;
            }

            # Para cada variante, crear un "producto" independiente con el nombre que incluye las opciones
            foreach ($variantsList as $v) {
                $variantId = $v['id'] ?? ($p['id'] ?? null);

                $price = isset($v['price']) ? (float) $v['price'] : (float) ($p['price'] ?? 0);
                $promotional = $v['promotional_price'] ?? $p['promotional_price'] ?? null;
                $stock = isset($v['stock']) ? (int) $v['stock'] : (int) ($p['stock'] ?? 0);
                $sku = $v['sku'] ?? null;
                $barcode = $v['barcode'] ?? null;
                $weight = $v['weight'] ?? null;
                $requiresShipping = $v['requires_shipping'] ?? $p['requires_shipping'] ?? true;

                # Construir partes de opciones de la variante
                $optionParts = [];

                # TiendaNube usa "values"
                if (!empty($v['values']) && is_array($v['values'])) {
                    foreach ($v['values'] as $val) {
                        if (is_array($val)) {
                            # Priorizar español, luego inglés
                            $value = $val['es'] ?? $val['en'] ?? null;
                            if (!empty($value)) {
                                $optionParts[] = trim($value);
                            }
                        }
                    }
                }

                # Compatibilidad genérica (por si otra plataforma usa options)
                if (empty($optionParts) && !empty($v['options']) && is_array($v['options'])) {
                    foreach ($v['options'] as $optVal) {
                        if ($optVal !== null && $optVal !== '') {
                            $optionParts[] = trim($optVal);
                        }
                    }
                }

                # Fallback por si no hay opciones
                if (empty($optionParts)) {
                    if (!empty($v['option_values']) && is_array($v['option_values'])) {
                        foreach ($v['option_values'] as $ov) {
                            if (is_array($ov)) {
                                $optionParts[] = trim($ov['name'] ?? $ov['value'] ?? '');
                            } else {
                                $optionParts[] = trim($ov);
                            }
                        }
                    } elseif (!empty($v['name'])) {
                        $optionParts[] = trim($v['name']);
                    }
                }

                $variantSuffix = !empty($optionParts) ? ' ' . implode(' ', $optionParts) : '';
                $variantName = trim(($productName ?? '') . $variantSuffix);

                # Imágenes: preferir imágenes de la variante si existen, sino usar las del padre
                $images = $productImages;
                if (!empty($v['images']) && is_array($v['images'])) {
                    $images = [];
                    foreach ($v['images'] as $vi) {
                        if (!empty($vi['src'])) {
                            $images[] = ['url' => $vi['src'], 'alt' => $vi['alt'] ?? null];
                        }
                    }
                }

                # Construir la entrada final
                $json[] = [
                    'id' => $variantId,
                    'name' => ['es' => $variantName],
                    'description' => ['es' => $productDesc],
                    'variants' => [[
                        'id' => $variantId,
                        'price' => $price,
                        'promotional_price' => $promotional,
                        'stock' => $stock,
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'weight' => $weight,
                        'width' => $v['width'] ?? null,
                        'height' => $v['height'] ?? null,
                        'depth' => $v['depth'] ?? null,
                        'requires_shipping' => (bool) $requiresShipping,
                        'options' => $optionParts
                    ]],
                    'images' => $images,
                    'published' => $productPublished,
                    'free_shipping' => $productFreeShipping,
                    'categories' => $productCategories
                ];
            }
        }

        return $json;
    }

    # Normaliza ordenes de TiendaNube al formato de Ecommerce
    private function normalizeOrders(array $items): array
    {
        $json = [];

        foreach ($items as $o) {

            /* ================= Cliente ================= */
            # Normalizo los datos del cliente
            $customer = [
                'id'    => $o['customer']['id'] ?? null,
                'name'  => $o['customer']['name'] ?? null,
                'email' => $o['customer']['email'] ?? null,
                'phone' => !empty($o['customer']['phone'])
                    ? '+' . ltrim($o['customer']['phone'], '+')
                    : null,
                'note'  => $o['customer']['note'] ?? null,
            ];

            /* ================= SHIPPING ADDRESS (NORMALIZADO) ================== */

            $ship = $o['shipping_address'] # Dirección de envío
                ?? $o['customer']['default_address']
                ?? [];

            # Normalizo la dirección de envío
            $shippingAddress = [
                'address1'  => $ship['address'] ?? null,
                'address2'  => $ship['floor'] ?? null,
                'city'      => $ship['city'] ?? null,
                'province'  => $ship['province'] ?? null,
                'country'   => $ship['country'] ?? null,
                'zip'       => $ship['zipcode'] ?? null,
                'latitude'  => null,
                'longitude' => null,
            ];

            /* ================= PRODUCTOS ================= */

            $products = [];

            # Recolectar productos de la orden
            foreach ($o['products'] ?? [] as $p) {

                # Normalizo los productos
                $products[] = [
                    'id' => $p['product_id'] ?? null,
                    'name' => $p['name'] ?? null,
                    'quantity' => (int) ($p['quantity'] ?? 0),
                    'unit_price_original' => (float) ($p['price'] ?? 0),
                    'unit_price_discounted' => null,
                    'sku' => $p['sku'] ?? null,
                    'barcode' => $p['barcode'] ?? null,
                    'inventory_quantity' => null,
                    'weight' => null,
                    'product_type' => null,
                    'description' => null,
                ];
            }

            /* ================= ORDER FINAL ================= */

            # Normalizo la orden
            $json[] = [
                'id' => $o['id'] ?? null,
                'number' => $o['number'] ?? null,
                'status' => $o['status'] ?? null,
                'payment_status' => $o['payment_status'] ?? null,
                'shipping_status' => $o['shipping_status'] ?? null,
                'total' => (float) ($o['total'] ?? 0),
                'subtotal' => $o['subtotal'] ?? null,
                'discount' => $o['discount'] ?? null,
                'shipping_cost_customer' => $o['shipping_cost_customer'] ?? null,
                'currency' => $o['currency'] ?? null,
                'customer' => $customer,
                'shipping_address' => $shippingAddress,
                'products' => $products,
                'note' => $o['note'] ?? null,
                'order_origin' => 'tiendanube'
            ];
        }

        return $json;
    }

    # Normalizo clientes de TiendaNube al formato de Ecommerce
    private function normalizeCustomers(array $items): array
    {
        $json = [];

        # Normalizo los clientes
        foreach ($items as $c) {

            $addr = $c['defaultAddress'] # Dirección
                ?? $c['default_address']
                ?? [];

            # Normalizo los datos del cliente
            $json[] = [
                'id'    => $c['id'] ?? null,
                'name'  => $c['name'] ?? null,
                'email' => $c['email'] ?? null,
                'phone' => !empty($c['phone']) ? '+' . ltrim($c['phone'], '+') : null, # Con código de país
                'note'  => $c['note'] ?? null,

                # Dirección
                'address' => [
                    'address1'  => $addr['address'] ?? null,
                    'address2'  => null,
                    'number'    => $addr['number'] ?? null,
                    'floor'     => $addr['floor'] ?? null,
                    'locality'  => $addr['locality'] ?? null,
                    'city'      => $addr['city'] ?? null,
                    'province'  => $addr['province'] ?? null,
                    'country'   => $addr['country'] ?? null,
                    'zip'       => $addr['zipcode'] ?? null,
                    'latitude'  => null,
                    'longitude' => null,
                ]
            ];
        }

        return $json;
    }

    /* ================= PRODUCTOS ================= */

    # Obtiene productos de TiendaNube y los normaliza
    protected function getProducts(): array
    {
        try {
            $response = $this->get('/products');

            if ($response['status'] === 'error') {
                return $response;
            }

            # Normalizo los productos
            return [
                'status' => 'ok',
                'code'   => $this->codeStr . '200',
                'answer' => 'Productos obtenidos',
                'data'   => $this->normalizeProducts($response['data'] ?? [])
            ];
        } catch (Throwable $e) {
            error_log('TiendaNube::getProducts -> ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno al consultar productos',
                'data'   => []
            ];
        }
    }

    # Crea un producto en TiendaNube y los normaliza
    protected function createItem(array $data): array
    {
        $payload = [
            "name" => [
                "es" => $data['item_nombre']
            ],
            "description" => [
                "es" => $data['descripcion']
            ],
            "published" => true,
            "free_shipping" => false,

            # Para simplificar, concatenamos las categorías en un string separado por comas para el campo "tags" de TiendaNube
            "categories" => $data['categoría'] ?? [],

            "variants" => [[
                "price" => (float)$data['precio'],
                "stock" => (int)$data['stock_actual'],
                "sku" => $data['codigo_interno'] ?? null,
                "barcode" => $data['codigo_barra'] ?? null,
                "weight" => (float)($data['dimensiones']['peso'] ?? 0),
                "width"  => (float)($data['dimensiones']['ancho'] ?? 0),
                "height" => (float)($data['dimensiones']['alto'] ?? 0),
                "depth"  => (float)($data['dimensiones']['largo'] ?? 0),
                "requires_shipping" => $data['servicio'] == 1 ? false : true
            ]]
        ];

        return $this->request('POST', '/products', $payload); # Enviar al API
    }

    # Actualiza un producto en TiendaNube y los normaliza
    protected function updateItem(string $externalId, array $data): array
    {
        $payload = [
            "name" => [
                "es" => $data['item_nombre']
            ],
            "description" => [
                "es" => $data['descripcion']
            ],
            "categories" => $data['categoría'] ?? []
        ];

        return $this->request('PUT', "/products/{$externalId}", $payload); # Enviar al API
    }

    /* ================= ORDENES ================= */

    protected function getOrders(): array
    {
        try {
            $response = $this->get('/orders');

            if ($response['status'] === 'error') {
                return $response;
            }

            # Normalizo las ordenes
            return [
                'status' => 'ok',
                'code'   => $this->codeStr . '200',
                'answer' => 'Órdenes obtenidas',
                'data'   => $this->normalizeOrders($response['data'] ?? [])
            ];
        } catch (Throwable $e) {
            error_log('TiendaNube::getOrders -> ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno al consultar órdenes',
                'data'   => []
            ];
        }
    }

    /* ================= CLIENTES ================= */

    # Obtiene los clientes de TiendaNube y los normaliza
    protected function getCustomers(): array
    {
        try {

            # Obtener los clientes de TiendaNube
            $response = $this->get('/customers');

            if ($response['status'] === 'error') {
                return $response;
            }

            # Normalizo los clientes
            return [
                'status' => 'ok',
                'code'   => $this->codeStr . '200',
                'answer' => 'Clientes obtenidos',
                'data'   => $this->normalizeCustomers($response['data'] ?? [])
            ];
        } catch (Throwable $e) {

            error_log('TiendaNube::getCustomers -> ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno al consultar clientes',
                'data'   => []
            ];
        }
    }

    # Crea un cliente en TiendaNube y los normaliza
    protected function createCustomer(array $data): array
    {
        $direccion = $data['direccion'] ?? []; # Normalizar dirección

        # Normalizar los datos del cliente para el payload de TiendaNube
        $payload = [
            "name"  => $data['nombre'] ?? null,
            "email" => $data['email'] ?? null,
            "phone" => $data['movil'] ?? $data['telefono'] ?? null,
            "note"  => $data['notas'] ?? null,
            "addresses" => [[
                "address"  => $direccion['calle'] ?? null,
                "number"   => $direccion['no_ext'] ?? null,
                "floor"    => $direccion['no_int'] ?? null,
                "locality" => $direccion['colonia'] ?? null,
                "city"     => $direccion['ciudad'] ?? null,
                "province" => $direccion['estado'] ?? null,
                "country"  => $direccion['pais'] ?? null,
                "zipcode"  => $direccion['cp'] ?? null,
                "phone"    => $data['movil'] ?? null,
                "name"     => $data['nombre'] ?? null
            ]]
        ];

        return $this->request('POST', '/customers', $payload); # Enviar al API
    }

    # Actualiza un cliente en TiendaNube y los normaliza
    protected function updateCustomer(string $externalId, array $data): array
    {
        # Normalizar dirección
        $direccion = $data['direccion'] ?? [];

        $payload = [
            "name"  => $data['nombre'] ?? null,
            "email" => $data['email'] ?? null,
            "phone" => $data['movil'] ?? $data['telefono'] ?? null,
            "note"  => $data['notas'] ?? null,
            "addresses" => [[
                "address"  => trim(($direccion['calle'] ?? '') . ' ' . ($direccion['no_ext'] ?? '')),
                "city"     => $direccion['ciudad'] ?? null,
                "province" => $direccion['estado'] ?? null,
                "country"  => $direccion['pais'] ?? null,
                "zipcode"  => $direccion['cp'] ?? null,
                "phone"    => $data['movil'] ?? null
            ]]
        ];

        return $this->request('PUT', "/customers/{$externalId}", $payload); # Enviar al API
    }
}
