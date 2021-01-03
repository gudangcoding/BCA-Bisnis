<?php
//sumber https://kursuskomputer.web.id
date_default_timezone_set('Asia/Jakarta');
class Bcaapi_lib {
  
  private $corporateId;
  private $norek;
  private $clientId;
  private $clientSecret;
  private $apiKey;
  private $apiSecret;
  private $url;
  private $accessToken;
  
  public function __construct(){
     
  }

  public function get_transactions_and_saldo($corporateId,$clientId,$clientSecret,$apiKey,$apiSecret,$url, $norek, $from,$to){
    $this->setCredential($corporateId,$clientId,$clientSecret,$apiKey,$apiSecret,$url);
    $data = $this->getMutasi($norek, $from, $to);
    $res['ok'] = 0;
    if(isset($data->Data)){
      $result = [];
      if(!empty($data->Data)){
        $current_month = date('m'); 
        $saldo_awal = $data->StartBalance;
        foreach($data->Data as $i=>$row){
          $desc = $row->TransactionName.' '.$row->Trailer;
          $desc = str_replace([' CR ',' DB '],' ', $desc);
          $desc = preg_replace('/[\s]+/mu', ' ', $desc);

          preg_match('/(?:TRSF|JASA) E-BANKING ([0-9]{2})\/([0-9]{2}) +/', $desc, $match);
          if(count($match)==3){
            $tgl = $match[2];
            $bln = $match[1];
          }else{
            preg_match('/(?:TRSF|JASA) E-BANKING ([0-9]{4})\/+/', $desc, $match);
            if(count($match)==2){
              $tgl = substr($match[1], 0,2);
              $bln = substr($match[1], 2,2);
            }else{
              preg_match('/SWITCHING TANGGAL :([0-9]{2})\/([0-9]{2})\/+/', $desc, $match);
              if(count($match)==3){
                $tgl = $match[2];
                $bln = $match[1];
              }else{
                $expDate = explode('/', $row->TransactionDate);
                if(count($expDate) <2) {
                  $expDate = [date('d'), date('m')];
                }
                $tgl = $expDate[0];
                $bln = $expDate[1];
              }
            }
          }
          $thn = ($current_month=='01' && $bln=='12')?date('Y', time() - 31536000):date('Y');       


          if($row->TransactionType=='D'){
            $debet = $row->TransactionAmount;
            $credit = 0;
            $saldo_akhir = $saldo_awal-$debet; 
          }else{
            $debet = 0;
            $credit = $row->TransactionAmount;
            $saldo_akhir = $saldo_awal+$credit;
          }
          $result[]=[
            'date'=>implode('-', [$thn,$bln,$tgl]),
            'description'=>$desc,
            'debet'=>$debet,
            'credit'=>$credit,
            'start_balance'=>$saldo_awal,
            'end_balance'=>$saldo_akhir,
          ];
          $saldo_awal=$saldo_akhir;
        }
      }
      //$res['ok']=1;
      $res['data']=$result;
    }else{
      $res['msg']  = isset($data->ErrorMessage->English)?$data->ErrorMessage->English:'Unknown error';
    }
    return $res;
  }

  private function setCredential($corporateId,$clientId,$clientSecret,$apiKey,$apiSecret,$url){
    $this->corporateId = $corporateId;
    $this->clientId = $clientId;
    $this->clientSecret = $clientSecret;
    $this->apiKey = $apiKey;
    $this->apiSecret = $apiSecret;
    $this->url = rtrim($url,'/');
  }

  public function getToken(){

    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->url.'/api/oauth/token',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
      CURLOPT_HTTPHEADER => array(
        'Authorization: Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
        'Content-Type: application/x-www-form-urlencoded',
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    $arr = json_decode($response);
    return isset($arr->access_token) ?$arr->access_token:null;
  }

  private function checkToken(){
    if(empty($this->accessToken)){
      $this->accessToken = $this->getToken();
    }
  }
  public function setToken($token){
    $this->accessToken = $token;
  }

  public function getMutasi($norek, $from, $to){
    $this->checkToken();
    $this->norek = $norek;
    $method = 'GET';
    $requestUrl = [
      'EndDate'=>$to,
      'StartDate'=>$from,
    ];
    $relativeUrl = '/banking/v3/corporates/'.$this->corporateId.'/accounts/'.$this->norek.'/statements?'.http_build_query($requestUrl);
    if(empty($this->accessToken)) return 'token cannot empty';
    
    $timestamp = $this->generateTimestamp();
        $signature = $this->generateSignature($method, $relativeUrl, $this->accessToken, $this->apiSecret, $timestamp, '');

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->url.$relativeUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => array(
        'accept: application/json',
        'content-type: application/json',
        'authorization: Bearer '.$this->accessToken,
        'x-bca-key: '.$this->apiKey,
        'x-bca-timestamp: '.$timestamp,
        'x-bca-signature: '.$signature,
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    return json_decode($response);
  }

  public function cekTransaksi($norek, $from, $to){
    $this->checkToken();
    $this->norek = $norek;
    $method = 'GET';
    $requestUrl = [
      'EndDate'=>$to,
      'StartDate'=>$from,
    ];
    $relativeUrl = '/banking/v3/corporates/'.$this->corporateId.'/accounts/'.$this->norek.'/statements?'.http_build_query($requestUrl);
    if(empty($this->accessToken)) return 'token cannot empty';
    
    $timestamp = $this->generateTimestamp();
        $signature = $this->generateSignature($method, $relativeUrl, $this->accessToken, $this->apiSecret, $timestamp, '');

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->url.$relativeUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => array(
        'accept: application/json',
        'content-type: application/json',
        'authorization: Bearer '.$this->accessToken,
        'x-bca-key: '.$this->apiKey,
        'x-bca-timestamp: '.$timestamp,
        'x-bca-signature: '.$signature,
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    return json_decode($response);
  }



    private function generateSignature($method='GET', $relativeUrl, $auth_token, $secret_key, $timestamp, $bodyToHash = [])
    {
      $method = strtoupper($method);
        $encoder = '';
        if (is_array($bodyToHash)) {
            ksort($bodyToHash);
            $encoder = json_encode($bodyToHash, JSON_UNESCAPED_SLASHES);
        }
        $string = implode(':',[$method,$relativeUrl,$this->accessToken, hash("sha256", $encoder), $timestamp]);
        return hash_hmac('sha256', $string, $secret_key, false);
    }

    private function generateTimestamp(){
      $now = microtime(true);
    return date('Y-m-d\TH:i:s',$now).'.'.substr($now, 0, 3).date('P');
    }

}


//contih sandbox
$corporateId = 'BCAAPI2016';
$norek = '0201245680';
$clientId = '9e9acf5c-ebc6-446e-85d8-461000ca5292';
$clientSecret = 'ff7fead6-b002-46b2-bf89-492077be65d9';
$apiKey = 'a042bd85-e209-4e54-8316-b34863764c62';
$apiSecret = 'cbf9b021-d800-4aab-afdb-18f9780f4920';
$url = 'https://sandbox.bca.co.id';
$from = '2016-08-29';
$to = '2016-09-01';

$obj = new Bcaapi_lib;
$mutasi = $obj->get_transactions_and_saldo($corporateId,$clientId,$clientSecret,$apiKey,$apiSecret,$url, $norek, $from,$to);
//$mutasi = $obj->getMutasi( $norek, $from,$to);
//print_r($mutasi);
 echo json_encode($mutasi);

