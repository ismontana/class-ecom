<?php

class JWT {
    public function decrypt($jwt) {
        try {
            $tokenParts = explode('.', $jwt);
            if (count($tokenParts) !== 3) {
                return null;
            }
            
            list($header, $payload, $signature) = $tokenParts;
            
            $payloadJson = $this->base64url_decode($payload);
            $payload = json_decode($payloadJson, true);
            
            if (!$payload || !isset($payload['mypos_id'])) {
                return null;
            }

            return $payload;
        } catch (Exception $e) {
            return null;
        }
    }

    private function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}