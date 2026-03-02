<?php
/*
class Amazon extends Ecommerce
{ 
    protected string $codeStr = 'AMZ_';
    private string $sellerId;
    private string $marketplaceId;
    private string $region = 'us-east-1';
    private string $endpoint = 'https://sellingpartnerapi-na.amazon.com';

    private array $config;
    private string $lwaAccessToken;
    private array $awsCredentials;

    public function __construct(array $session)
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT config
            FROM platforms_config
            WHERE mypos_id = ?
              AND platform = 'amazon'
              AND status = 'connected'
            LIMIT 1
        ");
        $stmt->execute([$session['mypos_id']]);
        $platform = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->config = json_decode($platform['config'] ?? '', true);

        foreach ([
            'client_id','client_secret','refresh_token',
            'seller_id','marketplace_id',
            'aws_access_key','aws_secret_key','role_arn'
        ] as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException("Config Amazon inválida: $key");
            }
        }

        $this->sellerId      = $this->config['seller_id'];
        $this->marketplaceId = $this->config['marketplace_id'];

        parent::__construct($this->endpoint);

        $this->lwaAccessToken = $this->getLwaAccessToken();
        $this->awsCredentials = $this->assumeRole();

        $this->headers = [
            'Content-Type: application/json',
            'x-amz-access-token: ' . $this->lwaAccessToken
        ];
    }

    // auth

    private function getLwaAccessToken(): string
    {
        $ch = curl_init('https://api.amazon.com/auth/o2/token');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->config['refresh_token'],
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret']
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);

        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $res['access_token'] ?? throw new RuntimeException('LWA error');
    }

    private function assumeRole(): array
    {
        $params = [
            'Action' => 'AssumeRole',
            'RoleArn' => $this->config['role_arn'],
            'RoleSessionName' => 'MyPOSAmazon',
            'Version' => '2011-06-15'
        ];

        $query = http_build_query($params);
        $url = "https://sts.amazonaws.com/?$query";

        $headers = $this->signAws('GET', 'sts.amazonaws.com', '/', $query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $xml = simplexml_load_string(curl_exec($ch));
        curl_close($ch);

        return [
            'key'    => (string)$xml->AssumeRoleResult->Credentials->AccessKeyId,
            'secret' => (string)$xml->AssumeRoleResult->Credentials->SecretAccessKey,
            'token'  => (string)$xml->AssumeRoleResult->Credentials->SessionToken,
        ];
    }

    // AWS signer

    private function signAws(string $method, string $host, string $uri, string $query = '', string $payload = ''): array
    {
        $time = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        $canonicalHeaders = "host:$host\nx-amz-date:$time\n";
        $signedHeaders = 'host;x-amz-date';

        $hashPayload = hash('sha256', $payload);

        $canonicalRequest = implode("\n", [
            $method,
            $uri,
            $query,
            $canonicalHeaders,
            $signedHeaders,
            $hashPayload
        ]);

        $scope = "$date/{$this->region}/execute-api/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n$time\n$scope\n" .
            hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->config['aws_secret_key'], true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return [
            "Authorization: AWS4-HMAC-SHA256 Credential={$this->config['aws_access_key']}/$scope, SignedHeaders=$signedHeaders, Signature=$signature",
            "x-amz-date: $time"
        ];
    }

    // Productos

    protected function apiGetProducts(): array
    {
        return $this->get(
            "/listings/2021-08-01/items/{$this->sellerId}?marketplaceIds={$this->marketplaceId}"
        );
    }

    protected function apiGetProductById(int $id): array
    {
        $sku = (string)$id;
        return $this->get(
            "/listings/2021-08-01/items/{$this->sellerId}/$sku?marketplaceIds={$this->marketplaceId}"
        );
    }

    protected function apiCreateProduct(array $payload): array
    {
        return $this->post(
            "/listings/2021-08-01/items/{$this->sellerId}",
            $payload
        );
    }

    protected function apiUpdateProduct(int $id, array $payload): array
    {
        $sku = (string)$id;
        return $this->put(
            "/listings/2021-08-01/items/{$this->sellerId}/$sku",
            $payload
        );
    }

    protected function apiDeleteProduct(int $id): array
    {
        return $this->apiUpdateProduct($id, [
            'status' => 'INACTIVE'
        ]);
    }

    protected function mapProductPayload(array $data): array
    {
        return [
            'productType' => 'PRODUCT',
            'requirements' => 'LISTING',
            'attributes' => [
                'title' => [[
                    'value' => $data['name'],
                    'language_tag' => 'es_MX'
                ]]
            ]
        ];
    }

    // Ordenes
    protected function apiGetOrders(): array
    {
        $date = gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days'));

        return $this->get(
            "/orders/v0/orders?MarketplaceIds={$this->marketplaceId}&CreatedAfter=$date"
        );
    }

    protected function apiGetOrderById(int $id): array
    {
        return $this->get("/orders/v0/orders/$id");
    }

    protected function apiCreateOrder(array $payload): array
    {
        return [
            'http_code' => 403,
            'error' => null,
            'body' => [
                'message' => 'Amazon no permite crear órdenes'
            ]
        ];
    }

    protected function apiUpdateOrder(int $id, array $payload): array
    {
        return [
            'http_code' => 403,
            'error' => null,
            'body' => [
                'message' => 'Amazon no permite modificar órdenes'
            ]
        ];
    }

    protected function mapOrderPayload(array $data): array
    {
        return [];
    }
}
*/