<?php

namespace App\Http\Controllers;

ini_set('max_execution_time', 300);

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Storage;

class Kandinsky extends Controller
{
  protected const API_KEY = "40387D793BB3A8774AE85C5D915BE1C7";
  protected const SECRET_KEY = "4F92AED3B5778C469CE635556D698BED";

  protected static $instance;

  public static function getInstance() {
    if (is_null(self::$instance)) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private static function get($url, $headers, $data) {
    $curl = curl_init();

    if (empty($data)) {
      $post = 0;
    } else {
      $post = 1;
    }

    curl_setopt_array($curl, [
      CURLOPT_URL => $url,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_POST => $post,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => 1,
    ]);
    if (!empty($data)) {
      if (!empty($data['params'])) {
        $json_curlfile = new \CURLStringFile(
          $data['params'],
          'request.json',
          'application/json'
        );
        curl_setopt($curl, CURLOPT_POSTFIELDS, [
          'model_id' => $data['model_id'],
          'params' => $json_curlfile,
        ]);
      } else {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
      }
    }

    $result = curl_exec($curl);
    return json_decode($result, true);
  }

  private static function get_model() {
    $url = "https://api-key.fusionbrain.ai/key/api/v1/models";
    $headers = [
      'X-Key: Key ' . self::API_KEY,
      'X-Secret: Secret ' . self::SECRET_KEY,
    ];
    $result = self::get($url, $headers, false);
    return $result[0]['id'];
  }

  public static function get_models() {
    $url = "https://api-key.fusionbrain.ai/key/api/v1/models";
    $headers = [
      'X-Key: Key ' . self::API_KEY,
      'X-Secret: Secret ' . self::SECRET_KEY,
    ];
    $result = self::get($url, $headers, false);
    return $result;
  }

  private static function check_generation(
    $request_id,
    $attempts = 10,
    $delay = 10
  ) {
    $url = "https://api-key.fusionbrain.ai/key/api/v1/text2image/status/";
    $headers = [
      'X-Key: Key ' . self::API_KEY,
      'X-Secret: Secret ' . self::SECRET_KEY,
    ];

    while ($attempts > 0) {
      $data = self::get($url . $request_id, $headers, false);
      if ($data['status'] == 'DONE') {
        return $data['images'];
      }
      $attempts -= 1;
      sleep($delay);
    }
    return false;
  }

  public static function promt($question, $size) {
    $model_id = self::get_model();

    if (!empty($question) && !empty($model_id)) {
      $url = "https://api-key.fusionbrain.ai/key/api/v1/text2image/run";
      $headers = [
        'X-Key: Key ' . self::API_KEY,
        'X-Secret: Secret ' . self::SECRET_KEY,
      ];
      
      $promt = self::get_promt($question, $size);

      $data = [
        "type" => "GENERATE",
        "numImages" => 1,
        "width" => $promt['size']['width'],
        "height" => $promt['size']['height'],
        "generateParams" => [
          "query" => $promt['question'],
        ],
      ];

      $request = self::get($url, $headers, [
        'model_id' => $model_id,
        'params' => json_encode($data),
      ]);

      $uuid = $request['uuid'];

      $image = self::check_generation($uuid);

      $res_img = file_get_contents('data:image/jpg;base64,' . $image[0]);

      $img_name = date('Y_m_d_H_i_s').'.jpg';

      Storage::disk('public')->put($img_name, $res_img);

      $url = Storage::url($img_name);

      return $url;
      return self::base64_to_jpeg($image[0], '123.jpg' );

      if ($image[0]) {
        file_put_contents(
          "/var/www/html/img/" . $uuid . ".jpg",
          file_get_contents('data:image/jpg;base64,' . $image[0])
        );
        $result = 'http://82.147.71.126/img/' . $uuid . '.jpg';
      } else {
        $result = 'Изображение не получено';
      }
    } else {
      $result = 'Model not found';
    }
    return $result;
  }

  public static function base64_to_jpeg($base64_string, $output_file) {
    // open the output file for writing
    $ifp = fopen( $output_file, 'wb' );
    // split the string on commas
    // $data[ 0 ] == "data:image/png;base64"
    // $data[ 1 ] == <actual base64 string>
    $data = explode( ',', $base64_string );
    // we could add validation here with ensuring count( $data ) > 1
    fwrite( $ifp, base64_decode( $data[ 1 ] ) );
    // clean up the file resource
    fclose( $ifp );
    return $output_file;
  }

  private static function get_promt($question, $size_img) {
    $promt = [];
    //$size = ['width' => 1024, 'height' => 1024];
    $size = ['width' => $size_img, 'height' => $size_img];
    $right_AR = ['16:9', '9:16', '3:2', '2:3'];

    if (preg_match("|\[(([0-9]{1,2}):([0-9]{1,2}))\]|si", $question, $aspect_ratio)) {
      $question = str_replace($aspect_ratio[0], '', $question);
      $key_AR = array_search($aspect_ratio, $right_AR);

      if (in_array($aspect_ratio[1], $right_AR)) {
        if ($aspect_ratio[2] > $aspect_ratio[3]) {
          $size['height'] = floor(
            ($size['height'] / $aspect_ratio[2]) * $aspect_ratio[3]
          );
        } else {
          $size['width'] = floor(
            ($size['width'] / $aspect_ratio[3]) * $aspect_ratio[2]
          );
        }
      }
    }
    
    $promt['question'] = $question;
    $promt['size'] = $size;
    
    return $promt;
  }
}
