<?php

class TiendaNube
{
    private string $user_Id;
    private string $api_token;
    private string $baseUrl;
    private array $headers;

    public function __construct()
    {
        $this->user_Id  = getenv('TIENDANUBE_USER_ID');
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
            return [];
        }

        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    # <--- PRODUCTS --->

    public function fetchRawProducts(array $params = []): array
    {
        $page = 1;
        $raw = [];

        do {

            $products = $this->request(
                'GET',
                "/products?page={$page}&per_page=200"
            );

            if (empty($products)) {
                break;
            }

            foreach ($products as $p) {

                $productName = is_array($p['name'] ?? null)
                    ? ($p['name']['es'] ?? $p['name']['en'] ?? null)
                    : ($p['name'] ?? null);

                $description = is_array($p['description'] ?? null)
                    ? ($p['description']['es'] ?? $p['description']['en'] ?? null)
                    : ($p['description'] ?? null);

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
                        'id' => $p['id'],
                        'values' => [],
                        'price' => $p['price'] ?? null,
                        'stock' => $p['stock'] ?? null,
                        'sku' => null,
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

    # <--- ORDERS --->

    public function fetchRawOrders(array $params = []): array
    {
        $allOrders = [];
        $page = 1;

        do {

            $response = $this->request(
                'GET',
                "/orders?page={$page}&per_page=200"
            );

            if (empty($response)) {
                break;
            }

            $allOrders = array_merge($allOrders, $response);

            $page++;
        } while (count($response) === 200);

        $orders = $allOrders;

        $raw = [];

        foreach ($orders as $o) {

            $phoneData = Utils::extractPhoneData($o['customer']['phone'] ?? null);
            $cliente = [
                'id'        => $o['customer']['id'] ?? null,
                'origen'    => 'TiendaNube',
                'telefono'  => $phoneData['numero'],
                'movil'     => $o['customer']['phone'] ?? null,
                'lada'      => $phoneData['lada'],
                'notas'     => $o['customer']['note'] ?? null,
                'nombre'    => $o['customer']['name'] ?? null,
                'rfc'       => null,
                'email'     => $o['customer']['email'] ?? null,
                'prospecto' => 0,
                'direccion' => [
                    'calle'     => $o['shipping_address']['address'] ?? null,
                    'no_ext'    => null,
                    'no_int'    => $o['shipping_address']['floor'] ?? null,
                    'colonia'   => null,
                    'cp'        => $o['shipping_address']['zipcode'] ?? null,
                    'municipio' => $o['shipping_address']['city'] ?? null,
                    'estado'    => $o['shipping_address']['province'] ?? null,
                    'ciudad'    => $o['shipping_address']['city'] ?? null,
                    'pais'      => $o['shipping_address']['country'] ?? null,
                    'referencias' => null,
                    'gps' => [
                        'latitud' => null,
                        'longitud' => null
                    ]
                ]
            ];

            $vendedor = [
                'aizu_id' => null,
                'user'    => 'TiendaNube',
                'nombre'  => 'TiendaNube'
            ];

            $pago = [
                'cuenta_receptora' => [
                    'id' => null,
                    'clabe' => null,
                    'beneficiario' => null,
                    'comision' => [
                        'aizu_id' => null,
                        'nombre' => null,
                        'porcentaje' => null,
                        'importe' => null
                    ]
                ],
                'cuenta_emisora' => null,
                'forma_pago' => null,
                'metodo_pago' => $o['payment_status'] ?? null
            ];

            $partes = [];

            foreach ($o['products'] ?? [] as $p) {

                $partes[] = [
                    'item_id'        => $p['product_id'],
                    'item_aizu_id'   => null,
                    'item_nombre'    => $p['name'],
                    'categoría'      => [],
                    'cant'           => $p['quantity'],
                    'precio'         => (float)$p['price'],
                    'codigo_barra'   => $p['barcode'] ?? null,
                    'codigo_interno' => $p['sku'] ?? null,
                    'stock_actual'   => null,
                    'descripcion'    => null,
                    'ficha_tecnica'  => null,
                    'servicio'       => 0,
                    'fiscal' => [
                        'unidad' => null,
                        'clave' => null,
                        'iva' => null,
                        'ieps' => null
                    ],
                    'dimensiones' => [
                        'alto' => null,
                        'ancho' => null,
                        'largo' => null,
                        'peso' => null
                    ]
                ];
            }

            $raw[] = [
                'id'      => $o['id'],
                'name'    => $o['number'] ?? null,
                'createdAt' => $o['created_at'] ?? null,
                'cliente' => $cliente,
                'vendedor' => $vendedor,
                'pago'    => $pago,
                'partes'  => $partes,
                'notas'   => $o['note'] ?? null
            ];
        }

        return $raw;
    }

    # <--- CUSTOMERS --->

    public function fetchRawCustomers(array $params = []): array
    {
        $allCustomers = [];
        $page = 1;

        do {

            $response = $this->request(
                'GET',
                "/customers?page={$page}&per_page=200"
            );

            if (empty($response)) {
                break;
            }

            $allCustomers = array_merge($allCustomers, $response);

            $page++;
        } while (count($response) === 200);

        $customers = $allCustomers;

        $raw = [];

        foreach ($customers as $c) {

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
                'direccion' => [
                    'calle' => $c['default_address']['address'] ?? null,
                    'no_ext' => null,
                    'no_int' => $c['default_address']['floor'] ?? null,
                    'colonia' => null,
                    'cp'    => $c['default_address']['zipcode'] ?? null,
                    'municipio' => $c['default_address']['city'] ?? null,
                    'estado' => $c['default_address']['province'] ?? null,
                    'ciudad' => $c['default_address']['city'] ?? null,
                    'pais'  => $c['default_address']['country'] ?? null,
                    'referencias' => null,
                    'gps' => [
                        'latitud' => null,
                        'longitud' => null
                    ]
                ]
            ];
        }

        return $raw;
    }
}
