<?php

abstract class Ecommerce # abstact para instanciar clases concretas de la plataforma (tiendaNube, shopify, etc)
{
    protected string $baseUrl;      # URL base de la API de la plataforma
    protected array $headers = [];  # Headers HTTP (Authorization, Content-Type, etc.)
    protected int $timeout = 30;    # Tiempo maximo de espera para requests

    protected string $mypos_id;
    protected string $client_id;

    protected string $codeStr = 'ECOM-';

    public function __construct(string $baseUrl, string $mypos_id, string $client_id)
    {
        try {
            if (empty($baseUrl)) {
                throw new RuntimeException('Base URL inválida');
            }

            $this->baseUrl   = rtrim($baseUrl, '/'); # Normaliza la URL base eliminando barras al final
            $this->mypos_id  = $mypos_id;
            $this->client_id = $client_id;
        } catch (Throwable $e) {
            print 'Ecommerce::__construct -> ' . $e->getMessage();
        }
    }

    /* ================= HTTP CORE ================= */

    # Metodo central para realizar peticiones HTTP a la API
    # Aquí pasan todos los GET, POST, PUT, DELETE
    protected function request(string $method, string $endpoint, array $payload = null): array
    {
        try {
            $ch = curl_init();

            $options = [
                CURLOPT_URL => $this->baseUrl . $endpoint, # URL completa
                CURLOPT_RETURNTRANSFER => true,            # Devuelve la respuesta como string
                CURLOPT_CUSTOMREQUEST => $method,          # (GET, POST, etc...)
                CURLOPT_TIMEOUT => $this->timeout,         # Timeout para evitar tiempo de espera indefinido
                CURLOPT_HTTPHEADER => $this->headers       # Headers definidos por la plataforma
            ];

            # Si se envia payload, se codifica como JSON
            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($curlError) {
                # Error de comunicación (red, DNS, timeout, etc.)
                return [
                    'status' => 'error',
                    'code'   => $this->codeStr . 'HTTP_' . ($httpCode ?: 500),
                    'answer' => 'Error de comunicación con la plataforma',
                    'data'   => null
                ];
            }

            # Respuesta ok
            return [
                'status' => 'ok',
                'code'   => $this->codeStr . (string)($httpCode ?: 200),
                'answer' => 'Petición exitosa',
                'data'   => json_decode($response, true)
            ];
        } catch (Throwable $e) {
            # Log interno
            error_log('Ecommerce::request -> ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno al realizar petición',
                'data'   => null
            ];
        }
    }

    # Wrapper para peticiones GET
    protected function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    /* ================= SINCRONIZACIÓN ================= */

    # Hago la sincronización completa (productos + ordenes)
    # Obtengo datos de la API y los guarda en la db
    final public function sync(): array # Final public para que se pueda llamar desde otros métodos y no se pueda sobreescribir en las plataformas
    {
        try {

            # Obtener productos y ordenes ya normalizados por la plataforma
            $productsResponse = $this->getProducts();
            $ordersResponse   = $this->getOrders();
            $customersResponse = $this->getCustomers();

            # Normalizo los datos de productos, ordenes y clientes
            $products = $productsResponse['data'] ?? [];
            $orders   = $ordersResponse['data'] ?? [];
            $customers = $customersResponse['data'] ?? [];

            # Transformar a la estructura de AIZU
            $itemsAizu   = $this->mapItems($products);
            $ordersAizu  = $this->mapOrders($orders);
            $customersAizu = $this->mapCustomers($customers);

            return [
                'status' => 'ok',
                'code'   => $this->codeStr . '200',
                'answer' => 'Sincronización obtenida',
                'data' => [
                    'items_count'  => count($itemsAizu),
                    'items'        => $itemsAizu,
                    'orders_count' => count($ordersAizu),
                    'orders'       => $ordersAizu,
                    'customers_count' => count($customersAizu),
                    'customers'       => $customersAizu,
                ]
            ];
        } catch (Throwable $e) {

            error_log('SYNC ERROR: ' . $e->getMessage());

            return [
                'status' => 'error',
                'code'   => $this->codeStr . '500',
                'answer' => 'Error interno durante la sincronización'
            ];
        }
    }

    # Transformo los productos obtenidos de la API a la estructura que AIZU espera
    protected function mapItems(array $products): array
    {
        $items = [];

        foreach ($products as $p) {

            $variant = $p['variants'][0] ?? [];
            $requiresShipping = $variant['requires_shipping'] ?? true;
            $servicio = $requiresShipping ? 0 : 1;

            $categoria = null;

            /* ================= CATEGORÍAS ================= */

            // Si existen categorías reales (TiendaNube)
            if (!empty($p['categories']) && is_array($p['categories'])) {

                $names = [];

                foreach ($p['categories'] as $cat) {
                    if (is_array($cat)) {
                        $names[] = $cat['name'] ?? null;
                    } else {
                        $names[] = $cat;
                    }
                }

                $names = array_filter($names);

                if (!empty($names)) {
                    $categoria = implode(', ', $names);
                }
            }

            //  Fallback a tags (Shopify)
            elseif (!empty($p['tags'])) {

                if (is_array($p['tags'])) {
                    $categoria = implode(', ', $p['tags']);
                } elseif (is_string($p['tags'])) {
                    $categoria = $p['tags'];
                }
            }

            /* ================= ITEM FINAL ================= */

            $items[] = [
                'item_id'        => $p['id'] ?? null,
                'item_aizu_id'   => null,
                'item_nombre'    => $p['name']['es'] ?? $p['name']['en'] ?? null,
                'categoría'      => $categoria,
                'precio'         => $variant['price'] ?? 0,
                'codigo_barra'   => $variant['barcode'] ?? null,
                'codigo_interno' => $variant['sku'] ?? null,
                'stock_actual'   => $variant['stock'] ?? 0,
                'descripcion'    => $p['description']['es'] ?? null,
                'ficha_tecnica'  => null,
                'servicio'       => $servicio,

                'fiscal' => [
                    'unidad' => null,
                    'clave'  => null,
                    'iva'  => ['tasa' => null, 'importe' => null],
                    'ieps' => ['tasa' => null, 'importe' => null, 'antes_iva' => null],
                ],

                'dimensiones' => [
                    'alto'  => $variant['height'] ?? null,
                    'ancho' => $variant['width'] ?? null,
                    'largo' => $variant['depth'] ?? null,
                    'peso'  => $variant['weight'] ?? null,
                ],
            ];
        }

        return $items;
    }

    # Transformo las ordenes obtenidas de la API a la estructura que AIZU espera
    protected function mapOrders(array $orders): array
    {
        $result = [];

        foreach ($orders as $o) {

            /* ================= Cliente ================= */

            $customerData = $o['customer'] ?? []; # Datos del cliente
            $addressData  = $o['shipping_address'] ?? []; # Datos de la dirección de envío (si existe)

            # Normaliza los datos del cliente
            $cliente = [
                'id' => $customerData['id'] ?? null,
                'aizu_id' => null,
                'telefono' => $customerData['phone'] ?? null,
                'movil' => $customerData['phone'] ?? null,
                'lada' => null, # Null hasta que sepa de donde saco esto, pq en ninguno de los dos me da esto
                'notas' => $customerData['note'] ?? null,
                'nombre' => $customerData['name'] ?? null,
                'rfc' => null,
                'email' => $customerData['email'] ?? null,
                'prospecto' => 0,
                'direccion' => [
                    'calle' => $addressData['address1'] ?? null,
                    'no_ext' => null,
                    'no_int' => $addressData['address2'] ?? null,
                    'colonia' => null,
                    'cp' => $addressData['zip'] ?? null,
                    'municipio' => $addressData['city'] ?? null,
                    'estado' => $addressData['province'] ?? null,
                    'ciudad' => $addressData['city'] ?? null,
                    'pais' => $addressData['country'] ?? null,
                    'referencias' => null,
                    'gps' => [
                        'latitud' => $addressData['latitude'] ?? null,
                        'longitud' => $addressData['longitude'] ?? null
                    ]
                ]
            ];

            /* ================= VENDEDOR ================= */

            $vendedor = [ # Usar la plataforma como vendedor(pendiente)
                'aizu_id' => '',
                'user' => 'Aizu 1',
                'nombre' => 'Aizu 1'
            ];

            /* ================= PAGO ================= */

            # Normaliza los datos del pago (base generica)
            $pago = [
                'cuenta_receptora' => [
                    'id' => '101010101',
                    'clabe' => '',
                    'beneficiario' => '',
                    'comision' => [
                        'aizu_id' => '',
                        'nombre' => 'Aizu 1',
                        'porcentaje' => 0,
                        'importe' => 0,
                    ],
                ],
                'cuenta_emisora' => '',
                'forma_pago' => 'CONTADO',
                'metodo_pago' => 'EFECTIVO',
            ];

            /* ================= PARTES ================= */

            $partes = [];

            # Normaliza las partes de la orden (items/productos de la orden)
            foreach ($o['products'] ?? [] as $p) {

                $precio = $p['unit_price_discounted'] # Precio original o descuento
                    ?? $p['unit_price_original']
                    ?? 0;

                # Agrego el producto a la lista de productos
                $partes[] = [
                    'item_id' => $p['id'] ?? null,
                    'item_aizu_id' => null,
                    'item_nombre' => $p['name'] ?? null,
                    'categoría' => $p['product_type'] ?? null,
                    'cant' => $p['quantity'] ?? 0,
                    'precio' => $precio,
                    'codigo_barra' => $p['barcode'] ?? null,
                    'codigo_interno' => $p['sku'] ?? null,
                    'stock_actual' => $p['inventory_quantity'] ?? null,
                    'descripcion' => $p['description'] ?? null,
                    'ficha_tecnica' => null,
                    'servicio' => 0,
                    'fiscal' => [
                        'unidad' => null,
                        'clave' => null,
                        'iva' => ['tasa' => null, 'importe' => null],
                        'ieps' => ['tasa' => null, 'importe' => null, 'antes_iva' => null],
                    ],
                    'dimensiones' => [
                        'alto' => null, # Null hasta que sepa de donde saco esto
                        'ancho' => null, # Null hasta que sepa de donde saco esto
                        'largo' => null, # Null hasta que sepa de donde saco esto
                        'peso' => $p['weight'] ?? null
                    ]
                ];
            }

            /* ================= OPERACIÓN FINAL ================= */

            $result[] = [
                'cliente' => $cliente,
                'vendedor' => $vendedor,
                'pago' => $pago,
                'partes' => $partes,
                'notas' => $o['note'] ?? null
            ];
        }

        return $result;
    }
    # Transformo los clientes obtenidos de la API a la estructura que AIZU espera
    protected function mapCustomers(array $customers): array
    {
        $result = [];

        # Transformo los clientes a la estructura de AIZU
        foreach ($customers as $c) {

            $addr = $c['address'] ?? []; # Dirección
            $telefono = $c['phone'] ?? null; # Teléfono

            # Normalizo los datos del cliente
            $result[] = [
                'id'        => $c['id'] ?? null,
                'aizu_id'   => null,
                'telefono'  => $telefono,
                'movil'     => $telefono,
                'lada'      => null,
                'notas'     => $c['note'] ?? null,
                'nombre'    => $c['name'] ?? null,
                'rfc'       => null,
                'email'     => $c['email'] ?? null,
                'prospecto' => 0,
                'direccion' => [
                    'calle'       => $addr['address1'] ?? null,
                    'no_ext'      => $addr['number'] ?? null,
                    'no_int'      => $addr['address2'] ?? $addr['floor'] ?? null,
                    'colonia'     => $addr['locality'] ?? null,
                    'cp'          => $addr['zip'] ?? null,
                    'municipio'   => $addr['city'] ?? null,
                    'estado'      => $addr['province'] ?? null,
                    'ciudad'      => $addr['city'] ?? null,
                    'pais'        => $addr['country'] ?? null,
                    'referencias' => null, # Null hasta que sepa de donde saco esto
                    'gps' => [
                        'latitud'  => $addr['latitude'] ?? null,
                        'longitud' => $addr['longitude'] ?? null
                    ]
                ]
            ];
        }

        return $result;
    }

    /* ================= Para la base de datos ================= */
    /* ================= PRODUCTOS ================= */

    # Sincroniza productos de la API a la base de datos
    # Espera un array NORMALIZADO por la plataforma
    # Realiza un "Upsert" (Insertar o Actualizar si existe)
    protected function syncProducts(mysqli $db, array $items): array
    {
        # JSON intermedio con los datos normalizados
        $json = [];

        foreach ($items as $i) {

            # Toma la primera variante como referencia principal
            $variant = $i['variants'][0] ?? [];

            # Intenta obtener nombre en español, si no existe usa lo que haya
            $nombre = $i['name']['es']
                ?? $i['name']['en']
                ?? json_encode($i['name'] ?? '');

            $descripcion = $i['description']['es']
                ?? $i['description']['en']
                ?? '';

            $imagen = $i['images'][0]['src'] ?? $i['images'][0]['url'] ?? null; # Normalizarlos <-

            # Mapeo de campos normalizados a columnas de la db
            $json[] = [
                'mypos_id'  => $this->mypos_id,
                'client_id' => $this->client_id,
                'plataforma' => static::class,
                'id_externo' => $i['id'] ?? null,
                'nombre' => $nombre,
                'descripcion' => strip_tags($descripcion),
                'precio' => $variant['price'] ?? 0,
                'precio_promocional' => $variant['promotional_price'] ?? null,
                'stock' => $variant['stock'] ?? 0,
                'sku' => $variant['sku'] ?? null,
                'barcode' => $variant['barcode'] ?? null,
                'peso' => $variant['weight'] ?? null,
                'ancho' => $variant['width'] ?? null,
                'alto' => $variant['height'] ?? null,
                'profundidad' => $variant['depth'] ?? null,
                'publicado' => !empty($i['published']) ? 1 : 0,
                'envio_gratis' => !empty($i['free_shipping']) ? 1 : 0,
                'imagen_url' => $imagen,
                'categorias' => json_encode($i['categories'] ?? []),
                'etiquetas' => json_encode($i['tags'] ?? []),
                'variantes' => json_encode($i['variants'] ?? []),
                'datos_completos' => json_encode($i, JSON_UNESCAPED_UNICODE),
                'sincronizado_at' => date('Y-m-d H:i:s')
            ];
        }

        # Inserción / actualización en db
        foreach ($json as $p) {
            $stmt = $db->prepare("
                INSERT INTO productos
                (mypos_id, client_id, plataforma, id_externo, nombre, descripcion, precio,
                precio_promocional, stock, sku, barcode, peso, ancho, alto, profundidad,
                publicado, envio_gratis, imagen_url, categorias, etiquetas, variantes,
                datos_completos, sincronizado_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                nombre=VALUES(nombre),
                descripcion=VALUES(descripcion),
                precio=VALUES(precio),
                precio_promocional=VALUES(precio_promocional),
                stock=VALUES(stock),
                sku=VALUES(sku),
                barcode=VALUES(barcode),
                peso=VALUES(peso),
                ancho=VALUES(ancho),
                alto=VALUES(alto),
                profundidad=VALUES(profundidad),
                publicado=VALUES(publicado),
                envio_gratis=VALUES(envio_gratis),
                imagen_url=VALUES(imagen_url),
                categorias=VALUES(categorias),
                etiquetas=VALUES(etiquetas),
                variantes=VALUES(variantes),
                datos_completos=VALUES(datos_completos),
                sincronizado_at=VALUES(sincronizado_at)
            ");

            foreach ($json as $p) {

                $stmt->bind_param(
                    "ssssssddissdddiisssssss",
                    $p['mypos_id'],
                    $p['client_id'],
                    $p['plataforma'],
                    $p['id_externo'],
                    $p['nombre'],
                    $p['descripcion'],
                    $p['precio'],
                    $p['precio_promocional'],
                    $p['stock'],
                    $p['sku'],
                    $p['barcode'],
                    $p['peso'],
                    $p['ancho'],
                    $p['alto'],
                    $p['profundidad'],
                    $p['publicado'],
                    $p['envio_gratis'],
                    $p['imagen_url'],
                    $p['categorias'],
                    $p['etiquetas'],
                    $p['variantes'],
                    $p['datos_completos'],
                    $p['sincronizado_at']
                );

                $stmt->execute();
            }

            $stmt->close();
        }

        foreach ($json as $p) {
            $stmt->execute($p);
        }

        # Respuesta pública filtrada
        return array_map(fn($p) => [
            'id_externo' => $p['id_externo'],
            'nombre'     => $p['nombre'],
            'precio'     => $p['precio'],
            'stock'      => $p['stock'],
            'imagen_url' => $p['imagen_url'],
        ], $json);
    }

    /* ================= ORDENES ================= */

    # Sincroniza ordenes de la API a la base de datos
    # Espera un array NORMALIZADO por la plataforma concreta
    protected function syncOrders(mysqli $db, array $items): array
    {
        $json = [];

        foreach ($items as $i) {

            # Calcula cantidad total de productos
            $productos = $i['products'] ?? [];
            $cantidadTotal = 0;

            foreach ($productos as $p) {
                $cantidadTotal += (int) ($p['quantity'] ?? 0);
            }

            $direccion = !empty($i['shipping_address'])
                ? json_encode($i['shipping_address'], JSON_UNESCAPED_UNICODE)
                : null;

            $json[] = [
                'mypos_id'  => $this->mypos_id,
                'client_id' => $this->client_id,
                'plataforma' => static::class,
                'id_externo' => $i['id'] ?? null,
                'numero_orden' => $i['number'] ?? null,
                'estado' => $i['status'] ?? null,
                'estado_pago' => $i['payment_status'] ?? null,
                'estado_envio' => $i['shipping_status'] ?? null,
                'total' => $i['total'] ?? 0,
                'subtotal' => $i['subtotal'] ?? null,
                'descuento' => $i['discount'] ?? null,
                'costo_envio' => $i['shipping_cost_customer'] ?? null,
                'moneda' => $i['currency'] ?? null,
                'cliente_nombre' => $i['customer']['name'] ?? null,
                'cliente_email' => $i['customer']['email'] ?? null,
                'cliente_telefono' => $i['customer']['phone'] ?? null,
                'direccion_envio' => $direccion,
                'productos' => json_encode($productos),
                'productos_cantidad' => $cantidadTotal,
                'notas' => $i['note'] ?? null,
                'origen' => $i['order_origin'] ?? null,
                'datos_completos' => json_encode($i, JSON_UNESCAPED_UNICODE),
                'sincronizado_at' => date('Y-m-d H:i:s')
            ];
        }

        foreach ($json as $data) {
            $stmt = $db->prepare("
                INSERT INTO ordenes
                (mypos_id, client_id, plataforma, id_externo, numero_orden, estado, estado_pago, estado_envio,
                total, subtotal, descuento, costo_envio, moneda,
                cliente_nombre, cliente_email, cliente_telefono, direccion_envio,
                productos, productos_cantidad, notas, origen, datos_completos, sincronizado_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                estado=VALUES(estado),
                estado_pago=VALUES(estado_pago),
                estado_envio=VALUES(estado_envio),
                total=VALUES(total),
                subtotal=VALUES(subtotal),
                descuento=VALUES(descuento),
                costo_envio=VALUES(costo_envio),
                moneda=VALUES(moneda),
                cliente_nombre=VALUES(cliente_nombre),
                cliente_email=VALUES(cliente_email),
                cliente_telefono=VALUES(cliente_telefono),
                direccion_envio=VALUES(direccion_envio),
                productos=VALUES(productos),
                productos_cantidad=VALUES(productos_cantidad),
                notas=VALUES(notas),
                origen=VALUES(origen),
                datos_completos=VALUES(datos_completos),
                sincronizado_at=VALUES(sincronizado_at)
                ");

            foreach ($json as $o) {

                $stmt->bind_param(
                    "ssssssssdddssssssisssss",
                    $o['mypos_id'],
                    $o['client_id'],
                    $o['plataforma'],
                    $o['id_externo'],
                    $o['numero_orden'],
                    $o['estado'],
                    $o['estado_pago'],
                    $o['estado_envio'],
                    $o['total'],
                    $o['subtotal'],
                    $o['descuento'],
                    $o['costo_envio'],
                    $o['moneda'],
                    $o['cliente_nombre'],
                    $o['cliente_email'],
                    $o['cliente_telefono'],
                    $o['direccion_envio'],
                    $o['productos'],
                    $o['productos_cantidad'],
                    $o['notas'],
                    $o['origen'],
                    $o['datos_completos'],
                    $o['sincronizado_at']
                );

                $stmt->execute();
            }

            $stmt->close();
        }

        foreach ($json as $o) {
            $stmt->execute($o);
        }
        return array_map(fn($o) => [
            'id_externo'   => $o['id_externo'],
            'numero_orden' => $o['numero_orden'],
            'total'        => $o['total'],
            'estado'       => $o['estado'],
            'cliente'      => $o['cliente_nombre'],
        ], $json);
    }

    /* ================= ACCIONES ================= */

    /* ================= PRODUCTOS ================= */
    final public function addItem(array $data): array
    {
        return $this->createItem($data);
    }

    final public function editItem(string $externalId, array $data): array
    {
        return $this->updateItem($externalId, $data);
    }

    /* ================= CLIENTES ================= */
    final public function addCustomer(array $data): array
    {
        return $this->createCustomer($data);
    }

    final public function editCustomer(string $externalId, array $data): array
    {
        return $this->updateCustomer($externalId, $data);
    }

    /* ================= ABSTRACT ================= */

    /* ================= PRODUCTOS ================= */

    # Cada plataforma debe implementar como obtiene sus productos y devolverlos YA NORMALIZADOS en ['data']
    abstract protected function getProducts(): array;
    # Cada plataforma debe implementar como crea un producto
    abstract protected function createItem(array $data): array;
    # Cada plataforma debe implementar como actualiza un producto
    abstract protected function updateItem(string $externalId, array $data): array;

    /* ================= ORDENES ================= */
    # Cada plataforma debe implementar como obtiene sus ordenes y devolverlas YA NORMALIZADAS en ['data']
    abstract protected function getOrders(): array;

    /* ================= CLIENTES ================= */
    # Cada plataforma debe implementar como obtiene sus clientes y devolverlos YA NORMALIZADOS en ['data']
    abstract protected function getCustomers(): array;
    # Cada plataforma debe implementar como crea un cliente
    abstract protected function createCustomer(array $data): array;
    # Cada plataforma debe implementar como actualiza un cliente
    abstract protected function updateCustomer(string $externalId, array $data): array;
}
