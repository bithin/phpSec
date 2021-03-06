<?php
/**
  phpSec - A PHP security library

  @author    Audun Larsen <larsen@xqus.com>
  @copyright Copyright (c) Audun Larsen, 2011
  @link      https://github.com/xqus/phpSec
  @license   http://opensource.org/licenses/mit-license.php The MIT License
  @package   phpSec
 */

/**
 * Implements validation of Yubikey against Yubico servers. This code is experimental.
 */
class phpsecYubikey {
  public static $_clientId     = null;
  public static $_clientSecret = null;
  public static $lastError     = null;

  private static $_charset = 'cbdefghijklnrtuv';

  /**
   * Verify Yubikey one time password against the Yubico servers.
   *
   * @param string $otp
   *   One time password to verify.
   *
   * @return boolean
   */
  public static function verify($otp) {
    if(self::$_clientId === null || self::$_clientSecret === null) {
      self::$lastError = 'YUBIKEY_CLIENT_DATA_NEEDED';
      return false;
    }

    if(!self::validOtp($otp)) {
      self::$lastError = 'YUBIKEY_INVALID_OTP';
      return false;
    }
    /* Setup the data needed to make the request. */
    $data['otp']       = $otp;
    $data['id']        = self::$_clientId;
    $data['nonce']     = phpsecRand::str(20);
    $data['timestamp'] = 1;
    $data['h']         = self::sign($data);

    /* Do the request. */
    $response = self::getResponse($data);
    if($response === false) {
      self::$lastError = 'YUBIKEY_SERVER_ERROR';
      return false;
    }

    /* If tokens don't match return false. */
    if($response['otp'] != $otp) {
      self::$lastError = 'YUBIKEY_NO_MATCH';
      return false;
    }

    /* Check status of response. If not OK return false. */
    if($response['status'] != 'OK') {
      switch($response['status']) {
        case 'REPLAYED_OTP':
          self::$lastError = 'YUBIKEY_SERVER_REPLAYED_OTP';
          break;
        case 'REPLAYED_REQUEST':
          self::$lastError = 'YUBIKEY_SERVER_REPLAYED_REQUEST';
          break;
        case 'BAD_OTP':
          self::$lastError = 'YUBIKEY_SERVER_BAD_OTP';
          break;
        case 'NO_SUCH_CLIENT':
          self::$lastError = 'YUBIKEY_SERVER_NO_SUCH_CLIENT';
          break;
        default:
          self::$lastError = 'YUBIKEY_SERVER_SAYS_NO';
          break;
      }
      return false;
    }

    /* Sign the request to see if it matches signature from server. */
    $signature = self::sign($response);
    if($signature !== $response['h']) {
      self::$lastError = 'YUBIKEY_BAD_SERVER_SIGNATURE';
      return false;
    }
    return true;
  }

  /**
   * Sign data using shared secret.
   *
   * @param array $data
   *   Data to sign.
   *
   * @return string
   *   Base64 encoded HMAC hash.
   */
  private static function sign($data) {
    /* Remove signature from server. */
    unset($data['h']);

    /* Sort keys alphabetically. */
    ksort($data);

    /* Build query string to sign. */
    $n = count($data);
    $query = '';
    $i = 0;
    while(list($key, $val) = each($data)) {
      $i++;
      $query .= $key.'='.$val;
      if($i < $n) {
        $query.= '&';
      }
    }

    /* Sign. */
    $sign = hash_hmac('sha1', utf8_encode($query), base64_decode(self::$_clientSecret), true);
    return base64_encode($sign);
  }

  /**
   * Make a request to the Yubico servers and get the response.
   *
   * @param array $data
   *   Array containing the key/values for the request.
   *
   * @return array
   *   Array containing key/values from the response.
   */
  private static function getResponse($data) {
    /* Convert the array with data to a request string. */
    $query = http_build_query($data);

    /* Set up array with options for the context used by file_get_contents(). */
    $opts = array(
      'http'=>array(
        'method' => "GET",
        'header' => "Accept-language: en\r\n" .
                    "User-Agent: phpSec (http://phpsec.xqus.com)\r\n"
      )
    );

    /* Create context. Allowing us to specify User-Agent. */
    $context = stream_context_create($opts);

    /* Get response from Yubico server. */
    $response = @file_get_contents('http://api.yubico.com/wsapi/2.0/verify?'.$query, null, $context);
    if($response === false) {
      /* Could not make request. */
      return false;
    }
    /* Parse response and create an array with the data. */
    $lines = explode("\r\n", $response);
     foreach($lines as $line) {
       if(trim($line) != '') {
         list($key, $val) = explode("=", $line, 2);
         $rdata[$key] = trim($val);
       }
    }

    /* All done. */
    return $rdata;
  }

  /**
   * Validate a string as a one-time-password.
   *
   * @param string $otp
   *   String to Validate
   *
   * @return boolean
   */
  private static function validOtp($otp) {
    $length  = strlen($otp);

    /* Check length. */
    if($length != 44) {
      return false;
    }

    /* Check for invalid characters. */
    for ($i = 0; $i < $length; $i=$i+2 ) {
      $high = strpos(self::$_charset, $otp[$i]);
      $low  = strpos(self::$_charset, $otp[$i+1]);
      if($high === false || $low === false) {
        return false;
      }
    }

    return true;
  }

  /**
   * Get the Yubico ID from a OTP.
   *
   * @param string $otp
   *   The one time password to get the ID from.
   *
   * @return string
   *   Returns the Yubikey ID, or FALSE on failure.
   */
  public static function getYubikeyId($otp) {
    if(!self::validOtp($otp)) {
      return false;
    }
    return substr($otp, 0, 12);
  }
}