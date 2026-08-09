<?php
declare(strict_types=1);

final class RekaQueueClient
{
    public function __construct(private string $baseUrl, private string $apiKey) {}
    public function services(): array { return $this->request('GET','/api/public/services'); }
    public function availability(int $serviceId,string $date): array { return $this->request('GET','/api/public/availability?'.http_build_query(['service_id'=>$serviceId,'date'=>$date])); }
    public function register(array $data): array { return $this->request('POST','/api/public/registrations',$data); }
    public function registration(string $code): array { return $this->request('GET','/api/public/registrations/'.rawurlencode($code)); }
    public function checkIn(string $code): array { return $this->request('POST','/api/public/check-in',['registration_code'=>$code]); }
    private function request(string $method,string $path,?array $body=null): array
    {
        $curl=curl_init(rtrim($this->baseUrl,'/').$path);$headers=['Accept: application/json','X-API-Key: '.$this->apiKey];
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$body===null?$headers:[...$headers,'Content-Type: application/json']]);if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);if($response===false)throw new RuntimeException('Queue API connection failed: '.$error);$decoded=json_decode($response,true,512,JSON_THROW_ON_ERROR);if($status<200||$status>=300)throw new RuntimeException((string)($decoded['error']??'Queue API error'),$status);return $decoded['data']??$decoded;
    }
}
